<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Adapters;

use JsonException;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnrecognizedPayloadException;

class EventResolver
{
    public function __construct(
        private readonly SnapshotEventAdapter $snapshotAdapter,
        private readonly ThinEventAdapter $thinAdapter,
    ) {}

    public function resolve(string $rawPayload, ?string $signatureHeader, string $configKey = 'default'): WebhookEventDTO
    {
        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw UnrecognizedPayloadException::notJson($e->getMessage());
        }

        $object = $decoded['object'] ?? null;

        return match ($object) {
            'event' => $this->snapshotAdapter->resolve($rawPayload, $signatureHeader, $configKey),
            'v2.core.event' => $this->thinAdapter->resolve($rawPayload, $signatureHeader, $configKey),
            default => throw UnrecognizedPayloadException::unknownObjectType($object),
        };
    }
}
