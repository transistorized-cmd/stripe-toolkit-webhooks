<?php

declare(strict_types=1);

use Stripe\V2\Event as V2Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\EventSource;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\ThinEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

class ThinEventCapture extends StripeWebhookHandler
{
    /** @var array<int,array<string,mixed>> */
    public static array $captured = [];

    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        self::$captured[] = [
            'id' => $event->id(),
            'type' => $event->type(),
            'normalized' => $event->normalizedType(),
            'source' => $event->sourceFormat(),
            'is_thin_dto' => $event instanceof ThinEventDTO,
            'wraps_v2_event' => $event instanceof ThinEventDTO ? ($event->v2Event() instanceof V2Event) : null,
        ];
    }
}

beforeEach(function () {
    ThinEventCapture::$captured = [];
    StripeWebhook::route('stripe/webhook');
    config()->set('stripe-webhooks.queue.connection', 'sync');
    config()->set('stripe-webhooks.handlers', [
        'customer.created' => [ThinEventCapture::class],
    ]);
});

it('routes a thin v1.customer.created payload to a snapshot-named handler via normalizedType', function () {
    $payload = Fixtures::thinV1CustomerCreated();
    $body = SignedPayload::body($payload);
    $headers = [
        'HTTP_STRIPE_SIGNATURE' => SignedPayload::header($body, 'whsec_test_default'),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $body)->assertOk();

    $call = WebhookCall::query()->sole();
    expect($call->source)->toBe('thin')
        ->and($call->type)->toBe('v1.customer.created')
        ->and($call->status)->toBe(WebhookCall::STATUS_PROCESSED)
        ->and($call->related_object_id)->toBe('cus_QXYZabc123');

    $run = WebhookHandlerRun::query()->sole();
    expect($run->handler_class)->toBe(ThinEventCapture::class)
        ->and($run->status)->toBe(WebhookHandlerRun::STATUS_PROCESSED);

    expect(ThinEventCapture::$captured)->toHaveCount(1);
    $captured = ThinEventCapture::$captured[0];

    expect($captured['type'])->toBe('v1.customer.created')
        ->and($captured['normalized'])->toBe('customer.created')
        ->and($captured['source'])->toBe(EventSource::Thin)
        ->and($captured['is_thin_dto'])->toBeTrue()
        ->and($captured['wraps_v2_event'])->toBeTrue();
});
