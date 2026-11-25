<?php

namespace App\PayFlow;

use Illuminate\Support\Facades\DB;

final class Simulator
{
    public function submit(string $reference, string $kind, int $amount, ?string $scenario): array
    {
        DB::table('simulator_operations')->insertOrIgnore([
            'reference' => $reference,
            'provider_id' => 'sim_'.$reference,
            'state' => $scenario === 'decline' ? 'declined' : ($kind === 'refund' ? 'refunded' : 'captured'),
            'amount' => $amount,
        ]);

        return $this->retrieve('sim_'.$reference);
    }

    public function retrieve(string $providerId): array
    {
        $response = DB::table('simulator_operations')->where('provider_id', $providerId)->first();
        abort_unless($response, 404);

        return [
            'gateway' => 'simulator', 'provider_id' => $response->provider_id,
            'reference' => $response->reference, 'state' => $response->state,
            'amount' => (int) $response->amount, 'currency' => 'KWD',
        ];
    }
}
