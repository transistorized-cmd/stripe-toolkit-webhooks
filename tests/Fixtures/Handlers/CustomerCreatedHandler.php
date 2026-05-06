<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers;

use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('customer.created')]
class CustomerCreatedHandler extends StripeWebhookHandler
{
    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        // exists to verify normalized routing matches both 'customer.created'
        // and 'v1.customer.created' (thin)
    }
}
