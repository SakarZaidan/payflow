<?php

namespace App\PayFlow;

use Illuminate\Support\Facades\DB;
use LogicException;

final class Ledger
{
    /** Positive amounts are debits. Negative amounts are credits. */
    public function post(string $source, array $entries): void
    {
        if (DB::transactionLevel() === 0 || count($entries) < 2 || array_sum($entries) !== 0 || in_array(0, $entries, true)) {
            throw new LogicException('A journal must contain balanced entries inside a transaction.');
        }

        $id = DB::table('journals')->insertGetId([
            'source' => $source, 'currency' => 'KWD', 'posted_at' => now(),
        ]);

        DB::table('ledger_entries')->insert(collect($entries)->map(
            fn (int $amount, string $account) => ['journal_id' => $id, 'account' => $account, 'amount' => $amount]
        )->values()->all());
    }
}
