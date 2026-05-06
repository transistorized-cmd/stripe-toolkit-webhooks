<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers;

use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('should.not.be.discovered')]
abstract class AbstractBase extends StripeWebhookHandler
{
    public function handle(WebhookEventDTO $event): void
    {
        // abstract bases must be skipped by discovery
    }
}
