<?php

namespace App\PayFlow;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class Payments
{
    public const TERMINAL = ['captured', 'refunded', 'declined'];

    public function __construct(private Ledger $ledger) {}

    public function create(array $input, string $key, ?string $paymentId = null): stdClass
    {
        $scope = $paymentId ?? 'payment';
        $hash = hash('sha256', $key);
        ksort($input);
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $scope, $hash, $fingerprint, $paymentId) {
            $existing = DB::table('payment_operations')->where([
                'merchant' => 'demo', 'scope' => $scope, 'idempotency_hash' => $hash,
            ]);

            $payment = $paymentId === null ? null : $this->query($paymentId, 'payment')->lockForUpdate()->first();
            abort_if($paymentId !== null && ! $payment, 404);
            if ($operation = $existing->first()) {
                return $this->replay($operation, $fingerprint);
            }

            $amount = (int) $input['amount'];
            if ($payment) {
                abort_unless($payment->state === 'captured', 409, 'Only captured payments can be refunded.');
                $reserved = DB::table('payment_operations')->where('payment_id', $paymentId)
                    ->where('state', '!=', 'declined')->sum('amount');
                abort_if($amount > $payment->amount - $reserved, 409, 'Refund exceeds the available amount.');
            }

            $id = (string) Str::uuid();
            $inserted = DB::table('payment_operations')->insertOrIgnore([
                'id' => $id, 'payment_id' => $paymentId, 'merchant' => 'demo',
                'scope' => $scope, 'idempotency_hash' => $hash, 'request_hash' => $fingerprint,
                'kind' => $payment ? 'refund' : 'payment', 'rail' => $payment->rail ?? $input['rail'],
                'scenario' => $input['scenario'] ?? null,
                'gateway' => $payment->gateway ?? config('payflow.gateway'), 'state' => 'queued',
                'amount' => $amount, 'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                return $this->replay($existing->lockForUpdate()->firstOrFail(), $fingerprint);
            }

            DB::table('payment_outbox')->insert(['operation_id' => $id, 'available_at' => now()]);
            $this->event($id, 'queued', 'api');

            return $this->query($id)->firstOrFail();
        }, 5);
    }

    public function query(string $id, ?string $kind = null): Builder
    {
        return DB::table('payment_operations')->where(['id' => $id, 'merchant' => 'demo'])
            ->when($kind, fn ($query) => $query->where('kind', $kind));
    }

    private function replay(stdClass $operation, string $fingerprint): stdClass
    {
        abort_unless(hash_equals($operation->request_hash, $fingerprint), 409, 'Idempotency key already used for another request.');

        return $operation;
    }

    public function observe(string $id, array $result, string $source): void
    {
        DB::transaction(function () use ($id, $result, $source) {
            $operation = $this->query($id)->lockForUpdate()->firstOrFail();
            if (in_array($operation->state, self::TERMINAL, true)) {
                return;
            }
            if ($operation->gateway !== $result['gateway'] || $operation->currency !== $result['currency']
                || (int) $operation->amount !== $result['amount']
                || ($operation->provider_id && $operation->provider_id !== $result['provider_id'])
                || (! $operation->provider_id && $operation->id !== $result['reference'])) {
                throw new \UnexpectedValueException('Provider observation does not match the operation.');
            }

            if ($operation->kind === 'refund' && $operation->gateway === 'tap'
                && $this->query($operation->payment_id, 'payment')->value('provider_id') !== ($result['charge_id'] ?? null)) {
                throw new \UnexpectedValueException('Provider refund does not match the original charge.');
            }

            $state = $result['state'];
            if (! in_array($state, ['pending', 'declined', $operation->kind === 'refund' ? 'refunded' : 'captured'], true)) {
                throw new \UnexpectedValueException('Provider status is incompatible with the operation.');
            }
            $confirmed = in_array($state, ['captured', 'refunded'], true);
            $this->query($id)->update([
                'state' => $state, 'provider_id' => $result['provider_id'],
                'checkout_url' => $state === 'pending' ? ($result['checkout_url'] ?? $operation->checkout_url) : null,
                'confirmed_at' => $confirmed ? now() : null, 'updated_at' => now(),
            ]);
            if ($state !== $operation->state) {
                $this->event($id, $state, $source);
            }
            if ($confirmed) {
                $amount = $state === 'captured' ? $result['amount'] : -$result['amount'];
                $this->ledger->post($id, ['gateway_clearing' => $amount, 'merchant_payable' => -$amount]);
            }
        }, 5);
    }

    public function event(string $id, string $state, string $source): void
    {
        DB::table('payment_events')->insert([
            'operation_id' => $id, 'state' => $state, 'source' => $source, 'created_at' => now(),
        ]);
    }
}
