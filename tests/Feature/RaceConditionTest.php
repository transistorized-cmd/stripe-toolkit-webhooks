<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Jobs\ProcessStripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

beforeEach(function () {
    StripeWebhook::route('stripe/webhook');
    Queue::fake();
});

/*
 * Two near-simultaneous requests with the same event_id race to insert.
 * SQLite's UNIQUE constraint guarantees only one row exists. The loser
 * must NOT re-dispatch ProcessStripeWebhook — the winner already owns it.
 *
 * We simulate the race by pre-creating the WebhookCall row (as the
 * "winner" of an earlier microsecond) and then submitting the request
 * (the "loser"). The controller's persist() must catch the UNIQUE
 * collision, see the existing row, and short-circuit without dispatch.
 */

it('does not re-dispatch when a parallel request already created the row', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ];

    // The race winner has already inserted the row at status=received and
    // queued ProcessStripeWebhook.
    $existing = WebhookCall::query()->create([
        'stripe_event_id' => $event['id'],
        'type' => $event['type'],
        'livemode' => false,
        'api_version' => $event['api_version'],
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => $event,
        'related_object_id' => $event['data']['object']['id'],
        'status' => WebhookCall::STATUS_RECEIVED,
        'received_at' => now(),
    ]);

    $response = $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body);

    $response->assertOk();
    expect($response->json('status'))->toBe('in_progress')
        ->and($response->json('id'))->toBe($existing->id);

    expect(WebhookCall::query()->where('stripe_event_id', $event['id'])->count())->toBe(1);

    Queue::assertNotPushed(ProcessStripeWebhook::class);
});

it('reports duplicate when the parallel request already finished processing', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ];

    WebhookCall::query()->create([
        'stripe_event_id' => $event['id'],
        'type' => $event['type'],
        'livemode' => false,
        'api_version' => $event['api_version'],
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => $event,
        'related_object_id' => $event['data']['object']['id'],
        'status' => WebhookCall::STATUS_PROCESSED,
        'received_at' => now()->subSeconds(2),
        'processed_at' => now(),
    ]);

    $response = $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body);

    $response->assertOk();
    expect($response->json('status'))->toBe('duplicate');
    Queue::assertNotPushed(ProcessStripeWebhook::class);
});
