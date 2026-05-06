<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers;

use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('invoice.payment_failed')]
#[StripeEvent('invoice.payment_action_required')]
class MultiAttributeHandler extends StripeWebhookHandler
{
    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        // intentionally empty
    }
}
