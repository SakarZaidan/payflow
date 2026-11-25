<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$start = $argv[1] ?? '';
$goal = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
if (! preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $start) || $goal < 1) {
    fwrite(STDERR, "Usage: php ops/verify-db.php 'UTC timestamp' expected_count\n");
    exit(2);
}

$payments = DB::table('payment_operations as p')
    ->where('p.gateway', 'simulator')->where('p.kind', 'payment')->where('p.created_at', '>=', $start);
for ($attempt = 0; $attempt < 90; $attempt++) {
    $pending = (clone $payments)->where('p.state', '!=', 'captured')->count();
    if ($pending === 0) {
        break;
    }
    sleep(1);
}
$stats = (clone $payments)->leftJoin('simulator_operations as s', 's.reference', '=', 'p.id')
    ->leftJoin('journals as j', 'j.source', '=', 'p.id')
    ->selectRaw('count(*) as operations, count(s.reference) as gateway_operations, count(j.id) as journals,
        max(timestampdiff(second, p.created_at, p.confirmed_at)) as max_lag_seconds')->first();
$imbalance = DB::table('ledger_entries')->groupBy('journal_id')->havingRaw('sum(amount) <> 0')->get(['journal_id'])->count();
$orphaned = DB::table('simulator_operations as simulator')
    ->leftJoin('payment_operations as op', 'op.id', '=', 'simulator.reference')->whereNull('op.id')->count();
echo json_encode((array) $stats + ['pending' => $pending, 'unbalanced' => $imbalance, 'orphaned' => $orphaned], JSON_PRETTY_PRINT)."\n";
exit((int) $stats->operations === $goal && (int) $stats->gateway_operations === $goal
    && (int) $stats->journals === $goal && ! $pending && ! $imbalance && ! $orphaned ? 0 : 1);
