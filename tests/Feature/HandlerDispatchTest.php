<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookProcessed;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

class TestRecordingHandler extends StripeWebhookHandler
{
    public static array $received = [];

    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        self::$received[] = $event->id();
    }
}

class TestExplodingHandler extends StripeWebhookHandler
{
    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        throw new RuntimeException('boom');
    }
}

beforeEach(function () {
    TestRecordingHandler::$received = [];
    StripeWebhook::route('stripe/webhook');
    config()->set('stripe-webhooks.queue.connection', 'sync');
});

it('runs configured handlers for the event type', function () {
    Event::fake([WebhookProcessed::class]);

    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [TestRecordingHandler::class],
    ]);

    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(TestRecordingHandler::$received)->toBe(['evt_test_pi_succeeded_001']);

    $call = WebhookCall::query()->sole();
    expect($call->status)->toBe(WebhookCall::STATUS_PROCESSED);

    $run = WebhookHandlerRun::query()->sole();
    expect($run->status)->toBe(WebhookHandlerRun::STATUS_PROCESSED)
        ->and($run->handler_class)->toBe(TestRecordingHandler::class);

    Event::assertDispatched(WebhookProcessed::class);
});

it('marks the call as dead-lettered when a handler exhausts retries', function () {
    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [TestExplodingHandler::class],
    ]);

    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $call = WebhookCall::query()->sole();
    expect($call->status)->toBe(WebhookCall::STATUS_DEAD_LETTER);

    $run = WebhookHandlerRun::query()->sole();
    expect($run->status)->toBe(WebhookHandlerRun::STATUS_DEAD_LETTER)
        ->and($run->last_error)->toContain('boom');
});
