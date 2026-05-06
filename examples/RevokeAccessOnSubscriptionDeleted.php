<?php

declare(strict_types=1);

namespace App\Stripe\Handlers;

use App\Models\User;
use App\Notifications\SubscriptionEnded;
use Stripe\Subscription;
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

/**
 * Revoke premium access when a subscription is cancelled or expires.
 *
 * Note on event types:
 *   `customer.subscription.deleted` fires when the subscription's status
 *   becomes `canceled` (manual cancellation, end of trial without payment,
 *   exhaustion of dunning, etc). It is the canonical "cut access now"
 *   signal. If you offer a grace period instead, hook
 *   `customer.subscription.updated` and check `cancel_at_period_end`.
 *
 * Idempotency:
 *   `revokePremium()` is a state setter — calling it twice is harmless.
 *   We don't add explicit guards.
 */
#[StripeEvent('customer.subscription.deleted')]
class RevokeAccessOnSubscriptionDeleted extends StripeWebhookHandler
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 600, 3600];

    public function handle(WebhookEventDTO $event): void
    {
        /** @var Subscription $subscription */
        $subscription = $event->relatedObject();

        $user = User::where('stripe_id', $subscription->customer)->firstOrFail();

        $user->revokePremium();

        $user->notify(new SubscriptionEnded(
            endedAt: $event->createdAt(),
            wasManuallyCancelled: $subscription->status === 'canceled',
        ));
    }
}
