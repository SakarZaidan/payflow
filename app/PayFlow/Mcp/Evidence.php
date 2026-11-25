<?php

namespace App\PayFlow\Mcp;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Evidence
{
    public function database(): ConnectionInterface
    {
        $password = config('payflow.reader_password');
        if (! is_string($password) || $password === '') {
            throw new LogicException('Configure the MCP reader account.');
        }

        config(['database.connections.payflow_reader' => array_replace(config('database.connections.mysql'), [
            'url' => null, 'username' => 'payflow_reader', 'password' => $password,
        ])]);

        return DB::connection('payflow_reader');
    }

    public function transaction(string $id): ?array
    {
        $db = $this->database();
        $payment = $db->table('payment_operations')->where(['id' => $id, 'merchant' => 'demo', 'kind' => 'payment'])
            ->first(['id', 'rail', 'state', 'amount', 'currency', 'gateway', 'created_at', 'confirmed_at']);
        if (! $payment) {
            return null;
        }

        return [
            'evidence_id' => 'payment:'.$id,
            'payment' => (array) $payment,
            'events' => $db->table('payment_events')->where('operation_id', $id)->orderByDesc('id')->limit(25)
                ->get(['id', 'state', 'source', 'created_at'])->map(fn ($row) => ['evidence_id' => 'event:'.$row->id] + (array) $row)->all(),
        ];
    }

    public function refunds(string $id): ?array
    {
        $db = $this->database();
        if (! $db->table('payment_operations')->where(['id' => $id, 'merchant' => 'demo', 'kind' => 'payment'])->exists()) {
            return null;
        }

        $rows = $db->table('payment_operations')->where(['payment_id' => $id, 'merchant' => 'demo'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(26)
            ->get(['id', 'state', 'amount', 'currency', 'created_at', 'confirmed_at']);

        return ['payment_evidence_id' => 'payment:'.$id, 'truncated' => $rows->count() > 25,
            'refunds' => $rows->take(25)->map(fn ($row) => ['evidence_id' => 'refund:'.$row->id] + (array) $row)->all()];
    }

    public function reconciliation(int $id): ?array
    {
        $db = $this->database();
        $run = $db->table('reconciliation_runs')->where('id', $id)->first(['id', 'business_date', 'gateway', 'state', 'created_at']);
        if (! $run) {
            return null;
        }
        $rows = $db->table('reconciliation_items')->where('run_id', $id)->where('result', '!=', 'matched')
            ->orderBy('id')->limit(26)->get(['id', 'provider_id', 'result', 'expected', 'reported']);

        return ['evidence_id' => 'run:'.$id, 'run' => (array) $run, 'truncated' => $rows->count() > 25,
            'exceptions' => $rows->take(25)->map(fn ($row) => ['evidence_id' => 'exception:'.$row->id] + (array) $row)->all()];
    }
}
