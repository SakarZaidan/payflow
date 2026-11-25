<?php

use App\PayFlow\Reconciliation;
use App\PayFlow\Relay;
use App\PayFlow\Simulator;
use App\PayFlow\Webhooks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('payflow:relay {--watch}', function (Relay $relay) {
    if (! $this->option('watch')) {
        $this->info((string) $relay->dispatch());

        return;
    }

    while (true) {
        try {
            $relay->dispatch();
        } catch (Throwable $error) {
            report($error);
        }
        sleep(1);
    }
})->purpose('Queue due payment operations');

Artisan::command('payflow:reconcile {date?} {--gateway=simulator}', function (Reconciliation $reconciliation) {
    $day = $this->argument('date') ?? now('Asia/Kuwait')->subDay()->toDateString();
    $this->info($reconciliation->process($day, $this->option('gateway')));
})->purpose('Reconcile a closed Kuwait business date');

Artisan::command('payflow:import {date} {--gateway=simulator}', function (Reconciliation $reconciliation) {
    $raw = stream_get_contents(STDIN, 2_000_001);
    $this->info($reconciliation->import($this->argument('date'), $this->option('gateway'), $raw));
})->purpose('Import a normalized gateway statement from stdin and reconcile it');

Artisan::command('payflow:deliver {provider_id}', function (Simulator $simulator) {
    $secret = (string) config('payflow.simulator_secret');
    if ($secret === '') {
        $this->error('Configure the simulator secret first.');

        return 1;
    }
    $body = json_encode($simulator->retrieve($this->argument('provider_id')), JSON_THROW_ON_ERROR);
    $base = is_file('/.dockerenv') ? 'http://web:8080' : config('app.url');
    Http::withHeaders(['X-PayFlow-Signature' => hash_hmac('sha256', $body, $secret)])
        ->withBody($body, 'application/json')->timeout(5)->post($base.'/api/webhooks/simulator')->throw();
    $this->info('Signed simulator callback accepted.');
})->purpose('Replay a signed event for a simulator operation');

Schedule::call(fn () => app(Webhooks::class)->process())->name('payflow-webhooks')->everyFiveSeconds()->withoutOverlapping(1);
Schedule::command('payflow:reconcile', ['--gateway' => config('payflow.gateway')])->timezone('Asia/Kuwait')->dailyAt('01:00')
    ->withoutOverlapping(15);
