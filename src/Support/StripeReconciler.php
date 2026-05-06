<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

use DomainException;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\StripeObjectFetcher;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\SnapshotEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookReconciled;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

/**
 * Reconciles local state against Stripe when a webhook didn't make it
 * through cleanly — late, lost, signed with a stale secret, or simply
 * still propagating. Two entry points:
 *
 *   - `fetchObject($stripeId)` for app code that already has a Stripe
 *     id at hand and wants to read the authoritative state. The demo
 *     uses this on its order detail page.
 *
 *   - `reconcile(WebhookCall $call)` for stuck WebhookCall rows: refetch
 *     the related object, build a synthetic DTO with the fresh state,
 *     and re-run the handlers in-process so business state catches up.
 *
 * The Pro module wraps these in a `stripe-webhooks:reconcile` artisan
 * command (with `--pending`, `--older=` flags) and a Filament action.
 */
class StripeReconciler
{
    public function __construct(
        private readonly StripeObjectFetcher $fetcher,
        private readonly HandlerDiscovery $discovery,
    ) {}

    /**
     * Read-through to the underlying fetcher. App code uses this when it
     * has a stored Stripe id (`stripe_checkout_session_id`,
     * `stripe_payment_intent_id`, …) and wants to read the live state
     * without re-running webhook handlers.
     */
    public function fetchObject(string $stripeId): object
    {
        return $this->fetcher->fetch($stripeId);
    }

    /**
     * Refetch the related object for `$call` and re-run each handler
     * registered for the call's event type, against the fresh state.
     *
     * Handlers are run in-process (synchronous) — this is intended for
     * operator-triggered recovery, not for queue throughput. If you need
     * to reconcile a large batch use the Pro module's batched command.
     *
     * Idempotency is the handler's responsibility (the kit's docs say
     * so). Re-running on an already-paid order should be a no-op.
     */
    public function reconcile(WebhookCall $call): WebhookEventDTO
    {
        if ($call->related_object_id === null || $call->related_object_id === '') {
            throw new DomainException(
                "WebhookCall #{$call->id} has no related_object_id; nothing to reconcile."
            );
        }

        $fresh = $this->fetcher->fetch($call->related_object_id);

        $dto = $this->buildSyntheticDto($call, $fresh);

        foreach ($this->discovery->for($call->type) as $handlerClass) {
            app($handlerClass)->handle($dto);
        }

        WebhookReconciled::dispatch($call, $fresh);

        return $dto;
    }

    /**
     * Synthesize a snapshot-shaped DTO carrying the fresh related object.
     * Works for both source formats: even thin events get a snapshot
     * envelope here so handlers reading `relatedObject()` get the live
     * Stripe entity.
     */
    protected function buildSyntheticDto(WebhookCall $call, object $fresh): WebhookEventDTO
    {
        $payload = [
            'id' => $call->stripe_event_id,
            'object' => 'event',
            'api_version' => $call->api_version,
            'created' => $call->received_at->getTimestamp(),
            'data' => ['object' => $this->arrayify($fresh)],
            'livemode' => (bool) $call->livemode,
            'pending_webhooks' => 0,
            'request' => null,
            'type' => $call->type,
        ];

        return SnapshotEventDTO::fromArray($payload);
    }

    /**
     * @return array<string,mixed>
     */
    protected function arrayify(object $value): array
    {
        if (method_exists($value, 'toArray')) {
            $arr = $value->toArray();
            if (is_array($arr)) {
                return $arr;
            }
        }

        $encoded = json_encode($value);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
