<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\DTO;

use DateTimeImmutable;
use Stripe\Event as StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\EventSource;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\TypeNormalizer;

final class SnapshotEventDTO implements WebhookEventDTO
{
    public function __construct(
        private readonly StripeEvent $event,
        private readonly string $rawPayload,
    ) {}

    /**
     * Reconstruct from a stored payload (e.g. `WebhookCall::payload`)
     * for queue jobs that pick up the event after persistence.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $event = StripeEvent::constructFrom($payload);
        $raw = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($event, $raw);
    }

    public function id(): string
    {
        return (string) $this->event->id;
    }

    public function type(): string
    {
        return (string) $this->event->type;
    }

    public function normalizedType(): string
    {
        return TypeNormalizer::normalize($this->type());
    }

    public function createdAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp((int) $this->event->created);
    }

    public function apiVersion(): ?string
    {
        return $this->event->api_version ?? null;
    }

    public function accountId(): ?string
    {
        return $this->event->account ?? null;
    }

    public function livemode(): bool
    {
        return (bool) $this->event->livemode;
    }

    public function sourceFormat(): EventSource
    {
        return EventSource::Snapshot;
    }

    public function relatedObject(): mixed
    {
        return $this->event->data->object ?? null;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    /** Escape hatch for handlers that want the raw \Stripe\Event. */
    public function stripeEvent(): StripeEvent
    {
        return $this->event;
    }
}
