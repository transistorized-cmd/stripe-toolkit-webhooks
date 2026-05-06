<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

class ConnectAccountCapture extends StripeWebhookHandler
{
    public static ?string $captured = null;

    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        self::$captured = $event->accountId();
    }
}

beforeEach(function () {
    ConnectAccountCapture::$captured = null;
    StripeWebhook::route('stripe/webhook/{configKey}');
    config()->set('stripe-webhooks.queue.connection', 'sync');
    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [ConnectAccountCapture::class],
    ]);
});

it('exposes the connected account id end-to-end via $event->accountId()', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded(accountId: 'acct_1PZ8aBCConnectedAcc');
    $body = SignedPayload::body($payload);
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_connect'),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/stripe/webhook/connect', [], [], [], $headers, $body)->assertOk();

    expect(ConnectAccountCapture::$captured)->toBe('acct_1PZ8aBCConnectedAcc');
});

it('returns null accountId for a non-connect snapshot', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded();
    $body = SignedPayload::body($payload);
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ];

    StripeWebhook::route('stripe/webhook');
    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body)->assertOk();

    expect(ConnectAccountCapture::$captured)->toBeNull();
});
