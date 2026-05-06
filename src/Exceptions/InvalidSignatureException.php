<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvalidSignatureException extends RuntimeException implements HttpExceptionInterface
{
    public static function missingHeader(): self
    {
        return new self('Stripe-Signature header is missing.');
    }

    public static function noSecretConfigured(string $configKey): self
    {
        return new self(
            "No webhook secret configured for key [{$configKey}]. "
            .'Add it under config/stripe-webhooks.php → webhook_secrets.'
        );
    }

    public static function verificationFailed(string $reason): self
    {
        return new self("Stripe signature verification failed: {$reason}");
    }

    public function getStatusCode(): int
    {
        return 400;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
