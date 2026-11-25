<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_inbox', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->index(['processed_at', 'retry_at', 'id'], 'webhook_inbox_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_inbox', function (Blueprint $table) {
            $table->dropIndex('webhook_inbox_retry_index');
            $table->dropColumn(['attempts', 'retry_at']);
        });
    }
};
