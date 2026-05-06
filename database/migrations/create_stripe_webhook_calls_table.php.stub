<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('stripe-webhooks.tables.webhook_calls', 'stripe_webhook_calls'), function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->boolean('livemode')->default(false);
            $table->string('api_version')->nullable();
            $table->enum('source', ['snapshot', 'thin'])->default('snapshot');
            $table->string('config_key')->nullable()->index();
            $table->json('payload');
            $table->string('related_object_id')->nullable();
            $table->enum('status', [
                'received',
                'processing',
                'processed',
                'failed',
                'dead_letter',
            ])->default('received')->index();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('stripe-webhooks.tables.webhook_calls', 'stripe_webhook_calls'));
    }
};
