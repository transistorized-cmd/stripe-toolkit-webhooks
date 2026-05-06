<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers;

use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('payment_intent.succeeded')]
class RecorderHandler extends StripeWebhookHandler
{
    /** @var array<int,string> */
    public static array $received = [];

    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        self::$received[] = $event->id();
    }
}
