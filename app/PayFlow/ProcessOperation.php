<?php

namespace App\PayFlow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public function __construct(public string $operationId) {}

    public function handle(Payments $payments, Gateway $gateway): void
    {
        $lock = Cache::lock('payflow-operation:'.$this->operationId, 40);
        if (! $lock->get()) {
            return;
        }

        try {
            $outbox = DB::table('payment_outbox')->where('operation_id', $this->operationId);
            $work = $outbox->first();
            if (! $work) {
                return;
            }
            $operation = $payments->query($this->operationId)->firstOrFail();
            if (in_array($operation->state, Payments::TERMINAL, true)) {
                $outbox->delete();

                return;
            }

            $firstSent = $work->first_sent_at ? Carbon::parse($work->first_sent_at) : now();
            if (! $operation->provider_id && $firstSent->lt(now()->subHours(23))) {
                DB::transaction(function () use ($outbox, $payments, $operation) {
                    $current = $payments->query($operation->id)->lockForUpdate()->firstOrFail();
                    if (! $current->provider_id && ! in_array($current->state, Payments::TERMINAL, true)) {
                        $payments->query($operation->id)->update(['state' => 'needs_review', 'updated_at' => now()]);
                        $payments->event($operation->id, 'needs_review', 'retry_window');
                    }
                    $outbox->delete();
                });

                return;
            }

            $outbox->update(['first_sent_at' => $firstSent, 'attempts' => $work->attempts + 1]);
            try {
                $chargeId = $operation->payment_id ? $payments->query($operation->payment_id)->value('provider_id') : null;
                $result = $operation->provider_id
                    ? $gateway->retrieve($operation->gateway, $operation->provider_id)
                    : $gateway->submit($operation, $chargeId);
                $payments->observe($operation->id, $result, 'gateway');
                if (in_array($result['state'], Payments::TERMINAL, true)) {
                    $outbox->delete();
                } else {
                    $outbox->update(['available_at' => now()->addSeconds(10)]);
                }
            } catch (Throwable $error) {
                Log::warning('Gateway operation deferred', ['operation_id' => $operation->id, 'error_type' => $error::class]);
                $outbox->update(['available_at' => now()->addSeconds(min(300, 2 ** min(9, $work->attempts + 1)))]);
            }
        } finally {
            $lock->release();
        }
    }
}
