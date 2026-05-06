<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;

class WebhookDeadLettered
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookCall $webhookCall,
        public readonly WebhookHandlerRun $handlerRun,
    ) {}
}
