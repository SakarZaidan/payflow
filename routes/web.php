<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/checkout/return', function (Request $request) {
    $id = $request->query('tap_id');
    if (is_string($id) && preg_match('/^chg_[A-Za-z0-9_]{1,96}$/D', $id)) {
        $operation = DB::table('payment_operations')->where(['gateway' => 'tap', 'provider_id' => $id, 'state' => 'pending'])->value('id');
        if ($operation) {
            DB::table('payment_outbox')->where('operation_id', $operation)->update(['available_at' => now()]);
        }
    }

    return response('Checkout returned. Read the payment status from the PayFlow API.');
})->middleware('throttle:30,1');

Route::get('/login', fn () => view('console', ['page' => 'login']))->name('login');
Route::post('/login', function (Request $request) {
    $input = $request->validate(['password' => 'required|string|max:128']);
    $password = (string) config('payflow.operator_password');
    if ($password === '' || ! hash_equals($password, $input['password'])) {
        return back()->withErrors(['password' => 'That password did not match.']);
    }
    $request->session()->regenerate();
    $request->session()->put('payflow_operator', true);

    return redirect('/');
})->middleware('throttle:5,1');
Route::post('/logout', function (Request $request) {
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/login');
});

$operator = static fn (Request $request) => $request->session()->get('payflow_operator') === true;

Route::get('/', function (Request $request) use ($operator) {
    if (! $operator($request)) {
        return redirect('/login');
    }
    $filter = $request->validate(['state' => 'nullable|in:captured,declined,pending,queued,needs_review', 'rail' => 'nullable|in:knet,card']);
    $all = DB::table('payment_operations')->where(['merchant' => 'demo', 'kind' => 'payment']);
    $metrics = (clone $all)->selectRaw("count(*) as total, sum(case when state = 'captured' then amount else 0 end) as captured, sum(case when state = 'declined' then 1 else 0 end) as declined")->first();
    $payments = $all->when($filter['state'] ?? null, fn ($query, $state) => $query->where('state', $state))
        ->when($filter['rail'] ?? null, fn ($query, $rail) => $query->where('rail', $rail))
        ->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

    return view('console', ['page' => 'payments', 'payments' => $payments, 'filter' => $filter, 'metrics' => $metrics]);
});

Route::get('/payments/{id}', function (Request $request, string $id) use ($operator) {
    if (! $operator($request)) {
        return redirect('/login');
    }
    $payment = DB::table('payment_operations')->where(['merchant' => 'demo', 'kind' => 'payment', 'id' => $id])->first();
    abort_unless($payment, 404);
    $events = DB::table('payment_events')->where('operation_id', $id)->orderByDesc('id')->get();
    $refunds = DB::table('payment_operations')->where('payment_id', $id)->orderByDesc('created_at')->paginate(10);

    return view('console', compact('payment', 'events', 'refunds') + ['page' => 'detail']);
})->whereUuid('id');

Route::get('/settlement/{id?}', function (Request $request, ?int $id = null) use ($operator) {
    if (! $operator($request)) {
        return redirect('/login');
    }
    $runs = DB::table('reconciliation_runs')->orderByDesc('business_date')->orderByDesc('id')->limit(30)->get();
    $run = $id ? DB::table('reconciliation_runs')->find($id) : $runs->first();
    abort_if($id && ! $run, 404);
    $items = $run ? DB::table('reconciliation_items as item')
        ->leftJoin('payment_operations as operation', fn ($join) => $join->on('operation.provider_id', '=', 'item.provider_id')->where('operation.gateway', $run->gateway))
        ->where('item.run_id', $run->id)->select('item.*', 'operation.kind', 'operation.payment_id', 'operation.id as operation_id')
        ->orderByRaw("case when item.result = 'matched' then 1 else 0 end")->orderBy('item.id')->paginate(20) : null;
    $open = $run ? DB::table('reconciliation_items')->where('run_id', $run->id)->where('result', '!=', 'matched')->count() : 0;

    return view('console', compact('runs', 'run', 'items', 'open') + ['page' => 'settlement']);
})->whereNumber('id');
