<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $callsTable = config('stripe-webhooks.tables.webhook_calls', 'stripe_webhook_calls');

        Schema::create(config('stripe-webhooks.tables.handler_runs', 'stripe_webhook_handler_runs'), function (Blueprint $table) use ($callsTable) {
            $table->id();
            $table->foreignId('webhook_call_id')
                ->constrained($callsTable)
                ->cascadeOnDelete();
            $table->string('handler_class');
            $table->enum('status', [
                'pending',
                'running',
                'processed',
                'failed',
                'dead_letter',
            ])->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->enum('last_error_category', [
                'not_found',
                'schema_mismatch',
                'business_rule',
                'transient',
                'unknown',
            ])->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_call_id', 'handler_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('stripe-webhooks.tables.handler_runs', 'stripe_webhook_handler_runs'));
    }
};
