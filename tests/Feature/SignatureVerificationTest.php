<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Jobs\ProcessStripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

beforeEach(function () {
    StripeWebhook::route('stripe/webhook');
    StripeWebhook::route('stripe/webhook/{configKey}');
    Queue::fake();
});

it('rejects requests with no signature header', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';

    $this->postJson('/stripe/webhook', $event)
        ->assertStatus(400);

    expect(WebhookCall::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects requests with an invalid signature', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);
    $header = SignedPayload::header($body, 'whsec_wrong_secret');

    $response = $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertStatus(400);
    expect(WebhookCall::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts requests with a valid signature', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $body = SignedPayload::body($event);
    $header = SignedPayload::header($body, 'whsec_test_default');

    $response = $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();

    $call = WebhookCall::query()->sole();
    expect($call->stripe_event_id)->toBe('evt_test_pi_succeeded_001')
        ->and($call->type)->toBe('payment_intent.succeeded')
        ->and($call->config_key)->toBe('default')
        ->and($call->source)->toBe('snapshot')
        ->and($call->livemode)->toBeFalse();

    Queue::assertPushed(ProcessStripeWebhook::class);
});

it('uses the per-config-key secret when routed with {configKey}', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $event['id'] = 'evt_connect_001';
    $body = SignedPayload::body($event);
    $header = SignedPayload::header($body, 'whsec_test_connect');

    $response = $this->call('POST', '/stripe/webhook/connect', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();
    expect(WebhookCall::query()->sole()->config_key)->toBe('connect');
});

it('rejects a connect-routed request signed with the default secret', function () {
    $event = require __DIR__.'/../Fixtures/payment_intent_succeeded.php';
    $event['id'] = 'evt_connect_002';
    $body = SignedPayload::body($event);
    $header = SignedPayload::header($body, 'whsec_test_default');

    $response = $this->call('POST', '/stripe/webhook/connect', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertStatus(400);
    expect(WebhookCall::query()->count())->toBe(0);
});
