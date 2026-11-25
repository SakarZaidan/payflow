<?php

namespace App\PayFlow;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use stdClass;
use UnexpectedValueException;

final class Gateway
{
    public function __construct(private Simulator $simulator) {}

    public function submit(stdClass $operation, ?string $chargeId): array
    {
        if ($operation->gateway === 'simulator') {
            if (! config('payflow.simulator_url')) {
                return $this->simulator->submit($operation->id, $operation->kind, (int) $operation->amount, $operation->scenario);
            }

            return $this->simulatorClient()->post('/api/internal/gateway', [
                'reference' => $operation->id, 'kind' => $operation->kind,
                'amount' => (int) $operation->amount, 'scenario' => $operation->scenario,
            ])->throw()->json();
        }

        $reference = ['transaction' => $operation->id, 'order' => $operation->payment_id ?? $operation->id,
            'idempotent' => $operation->id];
        $body = ['amount' => (int) $operation->amount / 1000, 'currency' => 'KWD', 'reference' => $reference];
        if ($operation->kind === 'refund') {
            $body['reference'] = ['merchant' => $operation->id, 'idempotent' => $operation->id];
            $body += ['charge_id' => $chargeId, 'reason' => 'requested_by_customer'];
        } else {
            $body += [
                'customer_initiated' => true, 'threeDSecure' => true, 'save_card' => false,
                'customer' => ['first_name' => 'Sandbox', 'email' => config('payflow.tap_customer_email')],
                'source' => ['id' => $operation->rail === 'knet' ? 'src_kw.knet' : 'src_card'],
                'redirect' => ['url' => rtrim(config('app.url'), '/').'/checkout/return'],
            ];
        }

        $result = $this->tapResult($this->tap()->post($operation->kind === 'refund' ? '/refunds/' : '/charges', $body)->throw()->json());
        if ($operation->kind === 'refund' && $result['charge_id'] !== $chargeId) {
            throw new UnexpectedValueException('Tap refund belongs to a different charge.');
        }
        $result['reference'] ??= $operation->id;

        return $result;
    }

    public function retrieve(string $gateway, string $providerId): array
    {
        if ($gateway === 'simulator') {
            return config('payflow.simulator_url')
                ? $this->simulatorClient()->get('/api/internal/gateway/'.rawurlencode($providerId))->throw()->json()
                : $this->simulator->retrieve($providerId);
        }
        if ($gateway !== 'tap' || ! preg_match('/^(chg|re|ref)_[A-Za-z0-9_]+$/D', $providerId)) {
            throw new UnexpectedValueException('Invalid gateway reference.');
        }

        return $this->tapResult($this->tap()->get((str_starts_with($providerId, 'chg_') ? '/charges/' : '/refunds/').$providerId)->throw()->json());
    }

    public function tapSignature(array $payload): string
    {
        $secret = (string) config('payflow.tap_secret');
        if (! str_starts_with($secret, 'sk_test_') || ($payload['currency'] ?? null) !== 'KWD'
            || ! in_array($payload['object'] ?? null, ['charge', 'refund'], true)) {
            throw new UnexpectedValueException('Expected a KWD Tap sandbox charge or refund.');
        }
        $created = $payload['object'] === 'refund' ? ($payload['created'] ?? '') : data_get($payload, 'transaction.created', '');
        $message = 'x_id'.($payload['id'] ?? '').'x_amount'.self::decimal($this->fils($payload['amount'] ?? null))
            .'x_currencyKWDx_gateway_reference'.data_get($payload, 'reference.gateway', '')
            .'x_payment_reference'.data_get($payload, 'reference.payment', '')
            .'x_status'.($payload['status'] ?? '').'x_created'.$created;

        return hash_hmac('sha256', $message, $secret);
    }

    public static function decimal(int $amount): string
    {
        return ($amount < 0 ? '-' : '').intdiv(abs($amount), 1000).'.'.str_pad((string) (abs($amount) % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function fils(mixed $amount): int
    {
        if ((! is_int($amount) && ! is_float($amount) && ! is_string($amount))
            || ! preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,3}))?$/D', (string) $amount, $parts)) {
            throw new UnexpectedValueException('Invalid KWD amount.');
        }

        return ((int) $parts[1] * 1000) + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    private function tapResult(array $data): array
    {
        if (($data['live_mode'] ?? false) !== false || ($data['currency'] ?? null) !== 'KWD'
            || ! preg_match('/^(chg|re|ref)_[A-Za-z0-9_]+$/D', $data['id'] ?? '')) {
            throw new UnexpectedValueException('Unexpected Tap sandbox response.');
        }

        $state = match ($data['status'] ?? null) {
            'CAPTURED' => 'captured',
            'REFUNDED' => 'refunded',
            'DECLINED', 'CANCELLED', 'CANCELED', 'RESTRICTED', 'FAILED', 'ABANDONED', 'REJECTED' => 'declined',
            default => 'pending',
        };
        $url = $data['transaction']['url'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        $checkout = is_string($host) && str_ends_with(strtolower($host), '.tap.company')
            && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;

        return [
            'gateway' => 'tap', 'provider_id' => $data['id'],
            'reference' => str_starts_with($data['id'], 'chg_')
                ? (data_get($data, 'reference.transaction') ?? data_get($data, 'reference.idempotent'))
                : (data_get($data, 'reference.merchant') ?? data_get($data, 'reference.idempotent')),
            'charge_id' => $data['charge_id'] ?? data_get($data, 'charge.id'),
            'state' => $state,
            'amount' => $this->fils($data['amount'] ?? null), 'currency' => 'KWD',
            'checkout_url' => $checkout,
        ];
    }

    private function tap(): PendingRequest
    {
        $secret = (string) config('payflow.tap_secret');
        if (! str_starts_with($secret, 'sk_test_')) {
            throw new UnexpectedValueException('A Tap test key is required.');
        }

        return Http::baseUrl('https://api.tap.company/v2')->withToken($secret)->acceptJson()
            ->connectTimeout(3)->timeout(10)->withoutRedirecting();
    }

    private function simulatorClient(): PendingRequest
    {
        return Http::baseUrl(config('payflow.simulator_url'))->withToken((string) config('payflow.simulator_secret'))
            ->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting();
    }
}
