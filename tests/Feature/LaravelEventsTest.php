<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookDeadLettered;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookHandlerFailed;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookProcessed;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookReceived;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

class NonTerminalFailHandler extends StripeWebhookHandler
{
    public int $tries = 3;

    public array|int $backoff = 0;

    public function handle(WebhookEventDTO $event): void
    {
        throw new RuntimeException('fail but not yet exhausted');
    }
}

class AlwaysFailingHandler extends StripeWebhookHandler
{
    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        throw new DomainException('terminal failure');
    }
}

beforeEach(function () {
    StripeWebhook::route('stripe/webhook');
    config()->set('stripe-webhooks.queue.connection', 'sync');
});

it('fires WebhookReceived after persistence and before queue dispatch', function () {
    Event::fake([WebhookReceived::class]);

    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $e) {
        return $e->webhookCall->stripe_event_id === 'evt_test_pi_succeeded_001';
    });
});

it('fires WebhookHandlerFailed on a non-terminal failed attempt without dead-lettering', function () {
    Event::fake([WebhookHandlerFailed::class, WebhookDeadLettered::class]);

    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [NonTerminalFailHandler::class],
    ]);

    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Event::assertDispatchedTimes(WebhookHandlerFailed::class, 1);
    Event::assertNotDispatched(WebhookDeadLettered::class);

    $run = WebhookHandlerRun::query()->sole();
    expect($run->status)->toBe(WebhookHandlerRun::STATUS_FAILED)
        ->and($run->attempts)->toBe(1);
});

it('fires WebhookDeadLettered when retries are exhausted', function () {
    Event::fake([WebhookHandlerFailed::class, WebhookDeadLettered::class, WebhookProcessed::class]);

    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [AlwaysFailingHandler::class],
    ]);

    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Event::assertDispatched(WebhookHandlerFailed::class);
    Event::assertDispatched(WebhookDeadLettered::class);
    Event::assertNotDispatched(WebhookProcessed::class);

    $call = WebhookCall::query()->sole();
    expect($call->status)->toBe(WebhookCall::STATUS_DEAD_LETTER);
});
