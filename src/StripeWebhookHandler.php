<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks;

use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;

/**
 * Base class for user handlers. Extend it, optionally annotate with
 * #[StripeEvent('event.type')], and implement handle().
 *
 * The kit calls `handle()` from inside a queued job, so anything you do
 * here should be idempotent or guarded by your own business-level
 * idempotency keys — Stripe may legitimately deliver the same event more
 * than once across days.
 *
 * `$tries` and `$backoff` are read by RunStripeHandler when scheduling
 * retries on failure.
 */
abstract class StripeWebhookHandler
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    abstract public function handle(WebhookEventDTO $event): void;
}
