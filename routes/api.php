<?php

use App\PayFlow\Payments;
use App\PayFlow\Simulator;
use App\PayFlow\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$authenticate = static function (?string $provided, string $setting): void {
    $token = (string) config($setting);
    abort_unless($token !== '' && hash_equals($token, (string) $provided), 401);
};

$create = static function (Request $request, Payments $payments, ?string $id = null) use ($authenticate) {
    $authenticate($request->bearerToken(), 'payflow.merchant_token');
    $key = $request->header('Idempotency-Key');
    abort_unless(is_string($key) && preg_match('/^[!-~]{1,128}$/D', $key), 422, 'Provide a valid Idempotency-Key.');

    $input = $request->validate(['amount' => 'required|integer|min:1|max:100000000',
        'rail' => $id ? 'prohibited' : 'required|in:knet,card',
        'scenario' => $id || config('payflow.gateway') !== 'simulator' ? 'prohibited' : 'sometimes|in:decline',
    ]);
    $input['amount'] = (int) $input['amount'];
    $operation = $payments->create($input, $key, $id);
    $url = '/api/v1/'.($id ? 'refunds/' : 'payments/').$operation->id;

    return response()->json(['id' => $operation->id, 'status_url' => $url], 202)->header('Location', $url);
};

Route::post('/v1/payments', $create);
Route::post('/v1/payments/{id}/refunds', $create)->whereUuid('id');
foreach (['payments' => 'payment', 'refunds' => 'refund'] as $path => $kind) {
    Route::get('/v1/'.$path.'/{id}', function (Request $request, Payments $payments, string $id) use ($authenticate, $kind) {
        $authenticate($request->bearerToken(), 'payflow.merchant_token');
        $operation = $payments->query($id, $kind)->first();
        abort_unless($operation, 404);

        return response()->json(array_intersect_key((array) $operation, array_flip([
            'id', 'payment_id', 'kind', 'rail', 'state', 'amount', 'currency', 'checkout_url', 'created_at', 'updated_at',
        ])))->header('Cache-Control', 'no-store');
    })->whereUuid('id');
}

Route::post('/webhooks/{provider}', function (Request $request, Webhooks $webhooks, string $provider) {
    $webhooks->accept($request, $provider);

    return response()->noContent(202);
});

Route::post('/internal/gateway', function (Request $request, Simulator $simulator) use ($authenticate) {
    abort_unless(config('payflow.simulator_service'), 404);
    $authenticate($request->bearerToken(), 'payflow.simulator_secret');
    $input = $request->validate(['reference' => 'required|uuid', 'kind' => 'required|in:payment,refund',
        'amount' => 'required|integer|min:1|max:100000000', 'scenario' => 'nullable|in:decline']);

    return $simulator->submit($input['reference'], $input['kind'], (int) $input['amount'], $input['scenario'] ?? null);
});
Route::get('/internal/gateway/{id}', function (Request $request, Simulator $simulator, string $id) use ($authenticate) {
    abort_unless(config('payflow.simulator_service'), 404);
    $authenticate($request->bearerToken(), 'payflow.simulator_secret');

    return $simulator->retrieve($id);
});
