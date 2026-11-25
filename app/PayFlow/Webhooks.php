<?php

namespace App\PayFlow;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

final class Webhooks
{
    public function __construct(private Gateway $gateway, private Payments $payments) {}

    public function accept(Request $request, string $provider): void
    {
        abort_unless(in_array($provider, ['tap', 'simulator'], true), 404);
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413);
        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 422);

        try {
            $secret = (string) config('payflow.simulator_secret');
            $expected = match ($provider) {
                'tap' => $this->gateway->tapSignature($payload),
                'simulator' => $secret === '' ? throw new UnexpectedValueException : hash_hmac('sha256', $body, $secret),
            };
        } catch (UnexpectedValueException) {
            abort(401);
        }

        abort_unless(hash_equals($expected, (string) $request->header($provider === 'tap' ? 'hashstring' : 'X-PayFlow-Signature')), 401);
        $providerId = $payload[$provider === 'tap' ? 'id' : 'provider_id'] ?? null;
        abort_unless(is_string($providerId) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/D', $providerId), 422);

        $observation = null;
        if ($provider === 'simulator') {
            $input = validator($payload, [
                'reference' => 'required|uuid', 'state' => 'required|in:pending,captured,refunded,declined',
                'amount' => 'required|integer|min:1|max:100000000', 'currency' => 'required|in:KWD',
            ])->validate();
            $input['amount'] = (int) $input['amount'];
            $observation = json_encode($input + ['gateway' => 'simulator', 'provider_id' => $providerId], JSON_THROW_ON_ERROR);
        }

        DB::table('webhook_inbox')->insertOrIgnore([
            'gateway' => $provider, 'provider_id' => $providerId,
            'fingerprint' => hash('sha256', $provider === 'tap' ? $expected : $body),
            'observation' => $observation, 'created_at' => now(),
        ]);
    }

    public function process(): void
    {
        $inbox = DB::table('webhook_inbox')->whereNull('processed_at');
        foreach ((clone $inbox)->orderBy('id')->limit(100)->get() as $event) {
            try {
                $result = $event->observation
                    ? json_decode($event->observation, true, flags: JSON_THROW_ON_ERROR)
                    : $this->gateway->retrieve($event->gateway, $event->provider_id);
                $operation = DB::table('payment_operations')->where('gateway', $event->gateway)
                    ->where('provider_id', $event->provider_id)->first();
                if (! $operation && is_string($result['reference'])) {
                    $operation = $this->payments->query($result['reference'])->where('gateway', $event->gateway)->first();
                }
                if ($operation) {
                    $this->payments->observe($operation->id, $result, 'webhook');
                }
                (clone $inbox)->where('id', $event->id)->update(['processed_at' => now()]);
            } catch (Throwable $error) {
                Log::warning('Webhook processing deferred', ['event_id' => $event->id, 'error_type' => $error::class]);
            }
        }
    }
}
