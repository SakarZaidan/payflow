<?php

namespace Database\Seeders;

use App\PayFlow\Payments;
use App\PayFlow\Reconciliation;
use App\PayFlow\Simulator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DatabaseSeeder extends Seeder
{
    public function run(Payments $payments, Simulator $simulator, Reconciliation $reconciliation): void
    {
        if (DB::table('payment_operations')->exists() || config('payflow.gateway') !== 'simulator') {
            return;
        }

        $date = now('Asia/Kuwait')->subDay()->setTime(15, 0)->toImmutable();
        $day = $date->toDateString();
        Date::setTestNow($date->utc());
        try {
            DB::transaction(function () use ($payments, $simulator, $day) {
                $items = [];
                foreach ([32850, 7400, 125000, 16800, 9400, 21550, 48000, 6400, 185000, 23500] as $index => $amount) {
                    Date::setTestNow(now()->addMinutes(17));
                    $input = ['amount' => $amount, 'rail' => $index % 3 === 0 ? 'card' : 'knet'];
                    if ($index === 6 || $index === 8) {
                        $input['scenario'] = 'decline';
                    }
                    $operation = $payments->create($input, 'demo-'.$index);
                    $result = $simulator->submit($operation->id, 'payment', $amount, $operation->scenario);
                    $payments->observe($operation->id, $result, 'gateway');
                    DB::table('payment_outbox')->where('operation_id', $operation->id)->delete();

                    if ($index === 3) {
                        $refund = $payments->create(['amount' => 3800], 'demo-refund', $operation->id);
                        $observation = $simulator->submit($refund->id, 'refund', 3800, null);
                        $payments->observe($refund->id, $observation, 'gateway');
                        DB::table('payment_outbox')->where('operation_id', $refund->id)->delete();
                        $items[] = ['provider_id' => $observation['provider_id'], 'amount' => -3800];
                    }

                    $statementAmounts = [32850, 7400, 125000, 16800, 9400, 21000, null, 6400, null, 23500];
                    if ($statementAmounts[$index] !== null) {
                        $items[] = ['provider_id' => $result['provider_id'], 'amount' => $statementAmounts[$index]];
                    }
                }

                $stored = Storage::disk('local')->put("reports/simulator/{$day}.json", json_encode([
                    'business_date' => $day, 'currency' => 'KWD', 'fee' => 850, 'payout' => 237700, 'items' => $items,
                ], JSON_THROW_ON_ERROR));
                if (! $stored) {
                    throw new \RuntimeException('Could not store the demo settlement statement.');
                }
            });
        } finally {
            Date::setTestNow();
        }

        $reconciliation->process($day, 'simulator');
    }
}
