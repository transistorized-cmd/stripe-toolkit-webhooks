<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

function makeCall(string $status, Carbon $receivedAt): WebhookCall
{
    return WebhookCall::query()->create([
        'stripe_event_id' => 'evt_'.bin2hex(random_bytes(4)),
        'type' => 'payment_intent.succeeded',
        'livemode' => false,
        'api_version' => '2024-06-20',
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => ['stub' => true],
        'related_object_id' => null,
        'status' => $status,
        'received_at' => $receivedAt,
    ]);
}

beforeEach(function () {
    config()->set('stripe-webhooks.retention.processed_days', 90);
    config()->set('stripe-webhooks.retention.failed_days', 365);
    config()->set('stripe-webhooks.retention.dead_letter_days', null);
});

it('deletes processed rows older than processed_days', function () {
    $old = makeCall('processed', now()->subDays(100));
    $fresh = makeCall('processed', now()->subDays(10));

    $this->artisan('stripe-webhooks:prune')->assertSuccessful();

    expect(WebhookCall::query()->find($old->id))->toBeNull()
        ->and(WebhookCall::query()->find($fresh->id))->not->toBeNull();
});

it('respects the failed_days bucket independently', function () {
    $oldFailed = makeCall('failed', now()->subDays(400));
    $stillFreshFailed = makeCall('failed', now()->subDays(200));

    $this->artisan('stripe-webhooks:prune')->assertSuccessful();

    expect(WebhookCall::query()->find($oldFailed->id))->toBeNull()
        ->and(WebhookCall::query()->find($stillFreshFailed->id))->not->toBeNull();
});

it('keeps dead_letter rows forever when dead_letter_days is null', function () {
    $ancient = makeCall('dead_letter', now()->subYears(5));

    $this->artisan('stripe-webhooks:prune')->assertSuccessful();

    expect(WebhookCall::query()->find($ancient->id))->not->toBeNull();
});

it('--dry-run reports counts without deleting', function () {
    $old = makeCall('processed', now()->subDays(120));

    $this->artisan('stripe-webhooks:prune', ['--dry-run' => true])->assertSuccessful();

    expect(WebhookCall::query()->find($old->id))->not->toBeNull();
});

it('--status filter limits which buckets are pruned', function () {
    $oldProcessed = makeCall('processed', now()->subDays(120));
    $oldFailed = makeCall('failed', now()->subDays(400));

    $this->artisan('stripe-webhooks:prune', ['--status' => ['processed']])->assertSuccessful();

    expect(WebhookCall::query()->find($oldProcessed->id))->toBeNull()
        ->and(WebhookCall::query()->find($oldFailed->id))->not->toBeNull();
});
