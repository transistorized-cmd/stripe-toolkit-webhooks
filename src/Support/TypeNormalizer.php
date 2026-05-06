<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

class TypeNormalizer
{
    /**
     * Strip any leading version segment from a Stripe event type so that
     * snapshot and thin variants of the same event match a single
     * canonical key.
     *
     *   v1.customer.created               → customer.created
     *   v2.core.event_destination.ping    → core.event_destination.ping
     *   payment_intent.succeeded          → payment_intent.succeeded   (unchanged)
     */
    public static function normalize(string $type): string
    {
        if (preg_match('/^v\d+\.(.+)$/', $type, $matches) === 1) {
            return $matches[1];
        }

        return $type;
    }
}
