<?php

declare(strict_types=1);

namespace App\Stripe\Handlers;

use App\Models\LedgerEntry;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Stripe\Charge;
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

/**
 * Reconcile a refund: mark the order, record a ledger entry, and notify
 * the accounting pipeline.
 *
 * Idempotency:
 *   We key the ledger entry by `stripe_event_id` so a duplicate delivery
 *   or manual replay leaves exactly one accounting record. The kit's
 *   event-level dedupe is the first line of defence; the unique key on
 *   the ledger is the second line for audit safety.
 *
 * Wrap the whole side effect in a DB transaction — partial failure
 * between updating the order and writing the ledger entry would leave
 * accounting books inconsistent.
 */
#[StripeEvent('charge.refunded')]
class ReconcileChargeRefunded extends StripeWebhookHandler
{
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function handle(WebhookEventDTO $event): void
    {
        /** @var Charge $charge */
        $charge = $event->relatedObject();

        DB::transaction(function () use ($event, $charge) {
            $order = Order::where('stripe_charge_id', $charge->id)->firstOrFail();

            $order->markRefunded(
                amount: $charge->amount_refunded,
                fullyRefunded: (bool) $charge->refunded,
            );

            LedgerEntry::query()->updateOrCreate(
                ['external_event_id' => $event->id()],
                [
                    'kind' => 'refund',
                    'order_id' => $order->id,
                    'amount_cents' => -1 * (int) $charge->amount_refunded,
                    'currency' => $charge->currency,
                    'occurred_at' => $event->createdAt(),
                ],
            );
        });
    }
}
