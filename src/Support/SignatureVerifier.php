<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\InvalidSignatureException;

/**
 * Resolves the Stripe webhook signing secret for a given config key.
 * Actual signature verification is delegated to the Stripe SDK
 * (`\Stripe\Webhook::constructEvent` for snapshot events,
 * `\Stripe\WebhookSignature::verifyHeader` for thin) inside the
 * respective adapters — they use HMAC-SHA256 with constant-time
 * comparison and enforce the timestamp tolerance.
 */
class SignatureVerifier
{
    public function resolveSecret(string $configKey): string
    {
        $secret = config("stripe-webhooks.webhook_secrets.{$configKey}");

        if (! is_string($secret) || $secret === '') {
            throw InvalidSignatureException::noSecretConfigured($configKey);
        }

        return $secret;
    }
}
