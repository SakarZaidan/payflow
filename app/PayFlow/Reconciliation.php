<?php

namespace App\PayFlow;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class Reconciliation
{
    public function __construct(private Ledger $ledger) {}

    public function process(string $day, string $gateway): string
    {
        [$start, $path] = $this->reportPath($day, $gateway);
        $disk = Storage::disk(config('payflow.report_disk'));
        $raw = $disk->exists($path) ? $disk->get($path) : null;
        $report = $raw === null ? null : $this->parse($raw, $day);
        $fingerprint = $raw === null ? null : hash('sha256', $raw);

        return DB::transaction(function () use ($gateway, $day, $start, $report, $fingerprint) {
            $key = ['business_date' => $day, 'gateway' => $gateway, 'report_id' => 'daily'];
            DB::table('reconciliation_runs')->insertOrIgnore($key + ['state' => 'waiting_for_report', 'created_at' => now()]);
            $run = DB::table('reconciliation_runs')->where($key)->lockForUpdate()->firstOrFail();

            if ($run->fingerprint !== null) {
                if ($fingerprint !== null && ! hash_equals($run->fingerprint, $fingerprint)) {
                    throw new RuntimeException('The report differs from the frozen source for this run.');
                }

                return $run->state;
            }
            if ($report === null) {
                return 'waiting_for_report';
            }

            $expected = DB::table('payment_operations')->where(['gateway' => $gateway, 'currency' => 'KWD'])
                ->whereIn('state', ['captured', 'refunded'])->where('confirmed_at', '>=', $start->utc())
                ->where('confirmed_at', '<', $start->addDay()->utc())->get()->keyBy('provider_id');
            $reported = collect($report['items'])->groupBy('provider_id');
            $rows = [];
            $statementTotal = array_sum(array_column($report['items'], 'amount'));
            foreach ($expected->keys()->merge($reported->keys())->unique() as $id) {
                $operation = $expected->get($id);
                $matches = $reported->get($id, collect());
                $amount = $operation ? (int) $operation->amount * ($operation->kind === 'refund' ? -1 : 1) : null;
                $actual = $matches->isEmpty() ? null : (int) $matches->first()['amount'];
                $result = match (true) {
                    $matches->count() > 1 => 'duplicate',
                    $actual === null => 'missing_from_report',
                    $amount === null => 'missing_from_ledger',
                    $actual !== $amount => 'amount_mismatch',
                    default => 'matched',
                };
                $rows[] = ['run_id' => $run->id, 'provider_id' => $id, 'expected' => $amount, 'reported' => $actual, 'result' => $result];
            }

            $cleared = ! collect($rows)->contains(fn (array $row) => $row['result'] !== 'matched')
                && $statementTotal === $report['payout'] + $report['fee'];
            if ($statementTotal !== $report['payout'] + $report['fee']) {
                $rows[] = ['run_id' => $run->id, 'provider_id' => 'payout-total', 'result' => 'payout_mismatch',
                    'expected' => $statementTotal, 'reported' => $report['payout'] + $report['fee']];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('reconciliation_items')->insert($chunk);
            }
            if ($cleared && $report['fee'] > 0) {
                $this->ledger->post('fee:'.$run->id, ['processing_fees' => $report['fee'], 'gateway_clearing' => -$report['fee']]);
            }
            if ($cleared && $report['payout'] > 0) {
                $this->ledger->post('payout:'.$run->id, ['cash' => $report['payout'], 'gateway_clearing' => -$report['payout']]);
            }
            $state = $cleared ? 'reconciled' : 'needs_review';
            DB::table('reconciliation_runs')->where('id', $run->id)->update(['state' => $state, 'fingerprint' => $fingerprint]);

            return $state;
        }, 5);
    }

    public function import(string $day, string $gateway, string $contents): string
    {
        [, $path] = $this->reportPath($day, $gateway);
        $this->parse($contents, $day);

        return Cache::lock('payflow-report:'.$gateway.':'.$day, 60)->block(5, function () use ($path, $contents, $gateway, $day) {
            $disk = Storage::disk(config('payflow.report_disk'));
            if ($disk->exists($path)) {
                if ($disk->get($path) !== $contents) {
                    throw new RuntimeException('A different statement already exists for this business date.');
                }
            } elseif (! $disk->put($path, $contents)) {
                throw new RuntimeException('Could not store the statement.');
            }

            return $this->process($day, $gateway);
        });
    }

    private function reportPath(string $day, string $gateway): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || ! in_array($gateway, ['simulator', 'tap'], true)) {
            throw new RuntimeException('Use YYYY-MM-DD and a known gateway.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'Asia/Kuwait');
        if ($start->format('Y-m-d') !== $day || ! $start->lt(now('Asia/Kuwait')->startOfDay())) {
            throw new RuntimeException('Use a valid, closed business date.');
        }

        return [$start, "reports/{$gateway}/{$day}.json"];
    }

    private function parse(string $raw, string $day): array
    {
        if (strlen($raw) > 2_000_000) {
            throw new RuntimeException('Report exceeds the import size limit.');
        }

        return Validator::make(json_decode($raw, true, flags: JSON_THROW_ON_ERROR), [
            'business_date' => 'required|in:'.$day, 'currency' => 'required|in:KWD',
            'fee' => 'required|integer:strict|min:0|max:1000000000000',
            'payout' => 'required|integer:strict|min:0|max:1000000000000',
            'items' => 'present|array|max:10000', 'items.*' => 'array:provider_id,amount',
            'items.*.provider_id' => 'required|string|alpha_dash|max:100',
            'items.*.amount' => 'required|integer:strict|between:-100000000,100000000|not_in:0',
        ])->validate();
    }
}
