<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

/**
 * Fired after StripeReconciler refetches the related object and re-runs
 * handlers. Hook this for audit trails ("we reconciled a stale call")
 * and alerting.
 */
class WebhookReconciled
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookCall $webhookCall,
        public readonly object $freshRelatedObject,
    ) {}
}
