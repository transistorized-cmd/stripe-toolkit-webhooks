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

it('persists a single row when the same event id is delivered twice', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);
    $secret = 'whsec_test_default';
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body)->assertOk();

    expect(WebhookCall::query()->where('stripe_event_id', $event['id'])->count())->toBe(1);
});

it('returns 200 with duplicate marker when the event is already processed', function () {
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
        'related_object_id' => null,
        'status' => WebhookCall::STATUS_PROCESSED,
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    $response = $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body);

    $response->assertOk();
    expect($response->json('status'))->toBe('duplicate');
    Queue::assertNotPushed(ProcessStripeWebhook::class);
});
