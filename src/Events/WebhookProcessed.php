<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

class WebhookProcessed
{
    use Dispatchable;

    public function __construct(public readonly WebhookCall $webhookCall) {}
}
