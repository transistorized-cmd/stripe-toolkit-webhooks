<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UnsupportedSdkVersionException extends RuntimeException implements HttpExceptionInterface
{
    public static function thinEventsNeedV17(): self
    {
        return new self(
            'Thin events require stripe/stripe-php ^17 with the V2 event '
            .'classes (\Stripe\V2\Event). Upgrade the SDK to handle '
            .'v2.core.event payloads.'
        );
    }

    public function getStatusCode(): int
    {
        return 500;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
