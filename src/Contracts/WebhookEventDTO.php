<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Contracts;

use DateTimeImmutable;

/**
 * Unified contract for Stripe webhook events. Independent of source
 * format (snapshot v1 webhooks vs thin v2 event destinations) so user
 * handlers receive the same shape either way.
 */
interface WebhookEventDTO
{
    /** Stripe event id (`evt_…`); the natural idempotency key. */
    public function id(): string;

    /**
     * Raw event type. Snapshot uses canonical names like
     * `payment_intent.succeeded`; thin events prepend `v1.` or `v2.core.`.
     */
    public function type(): string;

    /**
     * `type()` with any leading version segment (`v1.`, `v2.core.`)
     * stripped. Use this for routing handlers so a single handler matches
     * both snapshot (`payment_intent.succeeded`) and the corresponding
     * thin (`v1.payment_intent.succeeded`) flavor.
     */
    public function normalizedType(): string;

    public function createdAt(): DateTimeImmutable;

    /** Snapshot exposes the API version it was generated for; thin events do not. */
    public function apiVersion(): ?string;

    /**
     * Connected account id when the event came from a Stripe Connect
     * account. Null otherwise. Drawn from `event.account` on snapshot
     * and from the notification context on thin events.
     */
    public function accountId(): ?string;

    public function livemode(): bool;

    public function sourceFormat(): EventSource;

    /**
     * The related Stripe object — a native typed instance
     * (`\Stripe\PaymentIntent`, `\Stripe\Charge`, …) for snapshot. For
     * thin events this triggers a lazy API fetch the first time it's
     * called and caches the result. Returns `null` for events without a
     * related object.
     */
    public function relatedObject(): mixed;

    /** The raw payload string Stripe POSTed, for persistence and replay. */
    public function rawPayload(): string;
}
