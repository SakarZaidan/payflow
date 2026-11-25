<?php

namespace App\PayFlow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class Relay
{
    public function dispatch(int $limit = 500): int
    {
        $ids = DB::transaction(function () use ($limit) {
            $now = now();
            $ids = DB::table('payment_outbox')->where('available_at', '<=', $now)
                ->orderBy('available_at')->limit($limit)->lockForUpdate()->pluck('operation_id');
            if ($ids->isNotEmpty()) {
                DB::table('payment_outbox')->whereIn('operation_id', $ids)->update(['available_at' => $now->copy()->addMinute()]);
            }

            return $ids;
        }, 5);

        foreach ($ids as $id) {
            Queue::push(new ProcessOperation($id));
        }

        return $ids->count();
    }
}
