<?php

declare(strict_types=1);

namespace App\Stripe\Handlers;

use App\Models\Order;
use App\Notifications\OrderPaid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Stripe\PaymentIntent;
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

/**
 * Mark an order as paid and send the customer their receipt.
 *
 * Idempotency:
 *   The Stripe event_id makes the WHOLE event idempotent at the kit level.
 *   We add a second guard at the business level: if the order is already
 *   paid we return early, so re-running the handler manually (replay)
 *   doesn't double-send the receipt notification.
 *
 * Failure modes:
 *   - ModelNotFoundException → categorized as `not_found`. Usually means
 *     a webhook arrived before the order was committed (race between
 *     Stripe redirect and our DB transaction). Retry with backoff.
 *   - Anything else → `unknown`, retried up to $tries times.
 */
#[StripeEvent('payment_intent.succeeded')]
class FulfillOrderOnPaymentIntentSucceeded extends StripeWebhookHandler
{
    public int $tries = 6;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300, 900, 1800, 3600];

    public function handle(WebhookEventDTO $event): void
    {
        /** @var PaymentIntent $intent */
        $intent = $event->relatedObject();

        $order = Order::where('stripe_payment_intent_id', $intent->id)->firstOrFail();

        if ($order->isPaid()) {
            return;
        }

        $order->markPaid(
            amount: $intent->amount,
            currency: $intent->currency,
            paidAt: $event->createdAt(),
        );

        $order->user->notify(new OrderPaid($order));
    }
}
