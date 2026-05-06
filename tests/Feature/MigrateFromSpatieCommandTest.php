<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

beforeEach(function () {
    // Mimic spatie/laravel-stripe-webhooks' table on the test connection.
    Schema::create('webhook_calls', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('url')->nullable();
        $table->json('payload');
        $table->text('exception')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('webhook_calls');
});

function insertSpatieRow(array $payload, ?string $exception = null, string $name = 'stripe'): void
{
    DB::table('webhook_calls')->insert([
        'name' => $name,
        'url' => 'https://example.com/stripe/webhook',
        'payload' => json_encode($payload),
        'exception' => $exception,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay()->addMinute(),
    ]);
}

it('imports valid Stripe events from the Spatie table', function () {
    insertSpatieRow([
        'id' => 'evt_legacy_1',
        'type' => 'payment_intent.succeeded',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => ['object' => ['id' => 'pi_legacy_1', 'object' => 'payment_intent']],
    ]);

    $this->artisan('stripe-webhooks:migrate-from-spatie')->assertSuccessful();

    $call = WebhookCall::query()->where('stripe_event_id', 'evt_legacy_1')->sole();
    expect($call->status)->toBe(WebhookCall::STATUS_PROCESSED)
        ->and($call->source)->toBe('snapshot')
        ->and($call->type)->toBe('payment_intent.succeeded')
        ->and($call->related_object_id)->toBe('pi_legacy_1')
        ->and($call->processed_at)->not->toBeNull();
});

it('marks rows with an exception as dead_letter', function () {
    insertSpatieRow(
        ['id' => 'evt_legacy_2', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_2']]],
        exception: 'TypeError on handler dispatch'
    );

    $this->artisan('stripe-webhooks:migrate-from-spatie')->assertSuccessful();

    $call = WebhookCall::query()->where('stripe_event_id', 'evt_legacy_2')->sole();
    expect($call->status)->toBe(WebhookCall::STATUS_DEAD_LETTER)
        ->and($call->processed_at)->toBeNull();
});

it('skips duplicates that already exist in the kit table', function () {
    WebhookCall::query()->create([
        'stripe_event_id' => 'evt_dup',
        'type' => 'payment_intent.succeeded',
        'livemode' => false,
        'api_version' => null,
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => ['id' => 'evt_dup'],
        'related_object_id' => null,
        'status' => WebhookCall::STATUS_PROCESSED,
        'received_at' => now(),
    ]);

    insertSpatieRow([
        'id' => 'evt_dup',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_dup']],
    ]);

    $this->artisan('stripe-webhooks:migrate-from-spatie')
        ->assertSuccessful()
        ->expectsOutputToContain('skipped (duplicate event_id)');

    expect(WebhookCall::query()->where('stripe_event_id', 'evt_dup')->count())->toBe(1);
});

it('skips rows with malformed payload', function () {
    insertSpatieRow(['no_id_no_type' => 'malformed']);

    $this->artisan('stripe-webhooks:migrate-from-spatie')->assertSuccessful();

    expect(WebhookCall::query()->count())->toBe(0);
});

it('--dry-run reports counts without inserting', function () {
    insertSpatieRow([
        'id' => 'evt_dryrun',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_dr']],
    ]);

    $this->artisan('stripe-webhooks:migrate-from-spatie', ['--dry-run' => true])
        ->assertSuccessful();

    expect(WebhookCall::query()->count())->toBe(0);
});

it('respects --source-name filter', function () {
    insertSpatieRow(
        ['id' => 'evt_other', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_x']]],
        name: 'paddle'
    );
    insertSpatieRow(
        ['id' => 'evt_stripe', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_s']]],
        name: 'stripe'
    );

    $this->artisan('stripe-webhooks:migrate-from-spatie')->assertSuccessful();

    expect(WebhookCall::query()->pluck('stripe_event_id')->all())->toBe(['evt_stripe']);
});
