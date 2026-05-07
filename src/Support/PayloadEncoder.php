<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

/**
 * Re-encodes a decoded webhook payload back to its canonical JSON
 * representation. Used by both DTOs when hydrating from a stored
 * `array` payload (e.g. from `WebhookCall::payload`) and the original
 * raw string is no longer at hand.
 */
final class PayloadEncoder
{
    /** @param  array<string,mixed>  $payload */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
