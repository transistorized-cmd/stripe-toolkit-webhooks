<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UnrecognizedPayloadException extends RuntimeException implements HttpExceptionInterface
{
    public static function notJson(string $reason): self
    {
        return new self("Webhook payload is not valid JSON: {$reason}");
    }

    public static function unknownObjectType(mixed $value): self
    {
        $rendered = is_string($value) ? "\"{$value}\"" : gettype($value);

        return new self(
            'Cannot detect webhook format. Expected `object` to be "event" '
            ."or \"v2.core.event\", got: {$rendered}"
        );
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
