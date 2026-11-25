<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->nullable()->constrained('payment_operations')->restrictOnDelete();
            $table->string('merchant', 32);
            $table->string('scope', 48);
            $table->char('idempotency_hash', 64);
            $table->char('request_hash', 64);
            $table->string('kind', 12);
            $table->string('rail', 12);
            $table->string('scenario', 16)->nullable();
            $table->string('gateway', 16);
            $table->string('provider_id', 100)->nullable();
            $table->string('state', 20);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->text('checkout_url')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant', 'scope', 'idempotency_hash']);
            $table->unique(['gateway', 'provider_id']);
            $table->index(['merchant', 'kind', 'created_at', 'id']);
            $table->index(['payment_id', 'state']);
            $table->index(['gateway', 'currency', 'state', 'confirmed_at']);
        });

        Schema::create('payment_outbox', function (Blueprint $table) {
            $table->foreignUuid('operation_id')->primary()->constrained('payment_operations')->restrictOnDelete();
            $table->timestamp('available_at')->index();
            $table->timestamp('first_sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('payment_operations')->restrictOnDelete();
            $table->string('state', 20);
            $table->string('source', 24);
            $table->timestamp('created_at');
            $table->index(['operation_id', 'id']);
        });

        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 16);
            $table->string('provider_id', 100);
            $table->char('fingerprint', 64);
            $table->text('observation')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['gateway', 'fingerprint']);
            $table->index(['processed_at', 'id']);
        });

        Schema::create('simulator_operations', function (Blueprint $table) {
            $table->uuid('reference')->primary();
            $table->string('provider_id', 100)->unique();
            $table->string('state', 20);
            $table->unsignedBigInteger('amount');
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('source', 120)->unique();
            $table->char('currency', 3);
            $table->timestamp('posted_at');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->string('account', 32);
            $table->bigInteger('amount');
        });

        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->string('gateway', 16);
            $table->string('report_id', 64);
            $table->char('fingerprint', 64)->nullable();
            $table->string('state', 20);
            $table->timestamp('created_at');
            $table->unique(['business_date', 'gateway', 'report_id']);
        });

        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('reconciliation_runs')->restrictOnDelete();
            $table->string('provider_id', 100);
            $table->string('result', 24);
            $table->bigInteger('expected')->nullable();
            $table->bigInteger('reported')->nullable();
            $table->index(['run_id', 'result', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['reconciliation_items', 'reconciliation_runs', 'ledger_entries', 'journals', 'simulator_operations',
            'webhook_inbox', 'payment_events', 'payment_outbox', 'payment_operations'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
