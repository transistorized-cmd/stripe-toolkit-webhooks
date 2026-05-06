<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support;

class SignedPayload
{
    /**
     * Build a Stripe-Signature header value matching the format Stripe
     * uses (`t=…,v1=…`) so the SDK's verifier accepts our test payloads.
     */
    public static function header(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Encode an event array as JSON exactly the way it should be sent on
     * the wire. Returns the raw body — pair with `header()` for a
     * signed request.
     */
    public static function body(array $event): string
    {
        return (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
