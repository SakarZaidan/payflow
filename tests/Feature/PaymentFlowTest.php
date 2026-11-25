<?php

namespace Tests\Feature;

use App\PayFlow\ProcessOperation;
use App\PayFlow\Reconciliation;
use App\PayFlow\Relay;
use App\PayFlow\Simulator;
use App\PayFlow\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payflow.merchant_token' => 'merchant-test-token',
            'payflow.simulator_secret' => 'simulator-test-secret',
            'payflow.gateway' => 'simulator', 'payflow.simulator_url' => null,
            'payflow.operator_password' => 'operator-test-pass',
        ]);
    }

    private function submit(string $key, int $amount = 1250, string $rail = 'knet'): string
    {
        return $this->withToken('merchant-test-token')->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments', ['amount' => $amount, 'rail' => $rail])->assertAccepted()->json('id');
    }

    public function test_idempotent_submission_posts_one_balanced_journal(): void
    {
        $id = $this->submit('order-1');
        $this->assertSame($id, $this->submit('order-1'));
        $this->postJson('/api/v1/payments', ['rail' => 'knet', 'amount' => 1000])->assertConflict();
        $this->assertDatabaseCount('payment_outbox', 1);
        $this->assertSame(1, app(Relay::class)->dispatch());
        app()->call([new ProcessOperation($id), 'handle']);

        $this->getJson('/api/v1/payments/'.$id)->assertOk()->assertJsonPath('state', 'captured');
        $this->assertDatabaseCount('simulator_operations', 1);
        $this->assertDatabaseCount('journals', 1);
        $this->assertEquals([1250, -1250], DB::table('ledger_entries')->orderBy('id')->pluck('amount')->all());
    }

    public function test_refund_reservations_prevent_a_second_refund_from_overdrawing_capture(): void
    {
        $id = $this->submit('order-2');
        app(Relay::class)->dispatch();
        $route = '/api/v1/payments/'.$id.'/refunds';
        $refund = $this->withHeader('Idempotency-Key', 'refund-1')->postJson($route, ['amount' => 850])
            ->assertAccepted()->json('id');
        $this->postJson($route, ['amount' => 850])->assertAccepted()->assertJsonPath('id', $refund);
        $this->withHeader('Idempotency-Key', 'refund-2')->postJson($route, ['amount' => 450])->assertConflict();
        app(Relay::class)->dispatch();
        $this->getJson('/api/v1/refunds/'.$refund)->assertOk()->assertJsonPath('state', 'refunded');
        $this->assertDatabaseCount('journals', 2);
        $this->assertEquals(400, DB::table('ledger_entries')->where('account', 'gateway_clearing')->sum('amount'));
    }

    public function test_signed_event_replay_and_stale_observations_do_not_mutate_a_confirmed_state(): void
    {
        $id = $this->submit('order-3');
        $result = app(Simulator::class)->submit($id, 'payment', 1250, null);
        $raw = json_encode($result, JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/webhooks/simulator', server: ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYFLOW_SIGNATURE' => 'invalid'], content: $raw)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_inbox', 0);

        foreach ([$result, $result, array_replace($result, ['state' => 'pending'])] as $event) {
            $raw = json_encode($event, JSON_THROW_ON_ERROR);
            $this->call('POST', '/api/webhooks/simulator', server: ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYFLOW_SIGNATURE' => hash_hmac('sha256', $raw, 'simulator-test-secret')], content: $raw)->assertAccepted();
        }
        app(Webhooks::class)->process();
        app(Relay::class)->dispatch();
        $this->assertDatabaseCount('webhook_inbox', 2);
        $this->assertDatabaseCount('journals', 1);
        $this->getJson('/api/v1/payments/'.$id)->assertJsonPath('state', 'captured');
    }

    public function test_ambiguous_tap_response_reuses_reference_and_stops_past_retry_window(): void
    {
        config(['payflow.gateway' => 'tap', 'payflow.tap_secret' => 'sk_test_unit', 'payflow.tap_customer_email' => 'operator@example.test']);
        Http::fake(['api.tap.company/*' => Http::response([], 503)]);
        $id = $this->submit('tap-order');
        app(Relay::class)->dispatch();
        $this->travel(3)->seconds();
        app(Relay::class)->dispatch();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['reference']['idempotent'] === $id);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($id, $request['reference']['idempotent']);
        }

        $this->travel(24)->hours();
        app(Relay::class)->dispatch();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('payment_operations', ['id' => $id, 'state' => 'needs_review']);
    }

    public function test_tap_refund_result_binds_refund_id_to_the_original_charge(): void
    {
        config(['payflow.gateway' => 'tap', 'payflow.tap_secret' => 'sk_test_unit', 'payflow.tap_customer_email' => 'operator@example.test']);
        Http::fake([
            'https://api.tap.company/v2/charges' => Http::response(['id' => 'chg_demo', 'live_mode' => false,
                'amount' => 1.250, 'currency' => 'KWD', 'status' => 'CAPTURED']),
            'https://api.tap.company/v2/refunds/' => Http::response(['id' => 're_demo', 'charge_id' => 'chg_demo',
                'live_mode' => false, 'amount' => 0.5, 'currency' => 'KWD', 'status' => 'REFUNDED']),
        ]);
        $payment = $this->submit('tap-charge');
        app(Relay::class)->dispatch();
        $refund = $this->withHeader('Idempotency-Key', 'tap-refund')
            ->postJson('/api/v1/payments/'.$payment.'/refunds', ['amount' => 500])->assertAccepted()->json('id');
        app(Relay::class)->dispatch();

        $this->assertDatabaseHas('payment_operations', ['id' => $refund, 'provider_id' => 're_demo', 'state' => 'refunded']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.tap.company/v2/refunds/'
            && $request['charge_id'] === 'chg_demo' && $request['reference']['idempotent'] === $refund);
    }

    public function test_reconciliation_freezes_source_and_posts_fee_and_payout_once(): void
    {
        Storage::fake('local');
        $date = now('Asia/Kuwait')->subDay()->setTime(12, 0);
        $day = $date->toDateString();
        $this->travelTo($date->utc());
        $id = $this->submit('closed-day', 2030);
        app(Relay::class)->dispatch();
        $this->travelBack();
        $reconcile = app(Reconciliation::class);
        $this->assertSame('waiting_for_report', $reconcile->process($day, 'simulator'));

        $path = "reports/simulator/{$day}.json";
        $report = ['business_date' => $day, 'currency' => 'KWD', 'fee' => 30, 'payout' => 2000,
            'items' => [['provider_id' => 'sim_'.$id, 'amount' => 2030]]];
        $this->assertSame('reconciled', $reconcile->import($day, 'simulator', json_encode($report)));
        $this->assertSame('reconciled', $reconcile->process($day, 'simulator'));
        $this->assertDatabaseCount('journals', 3);
        $this->assertEquals(0, DB::table('ledger_entries')->where('account', 'gateway_clearing')->sum('amount'));
        Storage::disk('local')->put($path, json_encode(array_replace($report, ['fee' => 35])));
        $this->expectExceptionMessage('frozen source');
        $reconcile->process($day, 'simulator');
    }

    public function test_console_requires_a_session_and_renders_seeded_evidence(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->post('/login', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/login', ['password' => 'operator-test-pass'])->assertRedirect('/');
        Storage::fake('local');
        $this->seed();
        $this->get('/?state=declined')->assertOk()->assertSee('Payment history')->assertSee('Declined');
        $this->get('/settlement')->assertOk()->assertSee('Amount mismatch');
    }
}
