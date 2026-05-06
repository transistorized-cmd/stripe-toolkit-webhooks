<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\DTO;

use DateTimeImmutable;
use Stripe\Util\EventTypes;
use Stripe\V2\Event as V2Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\EventSource;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnsupportedSdkVersionException;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\TypeNormalizer;

/**
 * Wraps a thin (v2) Stripe event. The wrapped notification is a typed
 * V2 event when the SDK ships a subclass for that event type
 * (`\Stripe\Events\V1…Event`), otherwise the base `\Stripe\V2\Event`.
 *
 * `relatedObject()` performs a lazy API fetch the first time it is
 * called and caches the result. The fetch requires a configured
 * `\Stripe\Stripe::setApiKey()` (or per-call options) — without it the
 * SDK will throw, which is the correct behavior to surface the misconfig.
 */
final class ThinEventDTO implements WebhookEventDTO
{
    /** Sentinel string distinguishes "not yet fetched" from "fetched and was null". */
    private const UNFETCHED = '__unfetched__';

    private mixed $cachedRelatedObject = self::UNFETCHED;

    public function __construct(
        private readonly object $event,
        private readonly string $rawPayload,
    ) {}

    /**
     * Hydrate from a stored payload. Picks the most specific V2 event
     * subclass available in the installed SDK, falling back to the base.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! class_exists(V2Event::class)) {
            throw UnsupportedSdkVersionException::thinEventsNeedV17();
        }

        $type = (string) ($payload['type'] ?? '');
        $class = self::resolveEventClass($type);
        $event = $class::constructFrom($payload);
        $raw = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($event, $raw);
    }

    /** @return class-string */
    private static function resolveEventClass(string $type): string
    {
        if (class_exists(EventTypes::class) && isset(EventTypes::thinEventMapping[$type])) {
            return EventTypes::thinEventMapping[$type];
        }

        return V2Event::class;
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
        $created = $this->event->created ?? null;

        if ($created instanceof DateTimeImmutable) {
            return $created;
        }

        if (is_int($created)) {
            return (new DateTimeImmutable)->setTimestamp($created);
        }

        return new DateTimeImmutable((string) ($created ?? 'now'));
    }

    public function apiVersion(): ?string
    {
        return null;
    }

    public function accountId(): ?string
    {
        $context = $this->event->context ?? null;

        if ($context === null) {
            return null;
        }

        if (is_object($context)) {
            return $context->account ?? null;
        }

        if (is_string($context) && str_starts_with($context, 'acct_')) {
            return $context;
        }

        return null;
    }

    public function livemode(): bool
    {
        return (bool) ($this->event->livemode ?? false);
    }

    public function sourceFormat(): EventSource
    {
        return EventSource::Thin;
    }

    public function relatedObject(): mixed
    {
        if ($this->cachedRelatedObject !== self::UNFETCHED) {
            return $this->cachedRelatedObject;
        }

        $relatedObject = $this->event->related_object ?? null;
        if ($relatedObject === null) {
            return $this->cachedRelatedObject = null;
        }

        if (method_exists($this->event, 'fetchRelatedObject')) {
            return $this->cachedRelatedObject = $this->event->fetchRelatedObject();
        }

        return $this->cachedRelatedObject = $relatedObject;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    /** Escape hatch for advanced cases. */
    public function v2Event(): object
    {
        return $this->event;
    }
}
