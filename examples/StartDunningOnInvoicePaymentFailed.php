<?php

declare(strict_types=1);

namespace App\Stripe\Handlers;

use App\Actions\StartDunningSequence;
use App\Models\User;
use Stripe\Invoice;
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

/**
 * Kick off the in-app dunning sequence (notifications, retry schedule,
 * grace period) when Stripe reports a failed invoice charge.
 *
 * Idempotency:
 *   StartDunningSequence is itself idempotent — calling it on an already-
 *   active dunning state is a no-op. That lets us trust replay and Stripe
 *   re-deliveries without writing extra guards here.
 *
 * Backoff is short because an unpaid invoice is time-sensitive: every
 * additional minute the customer doesn't see the dunning email increases
 * churn risk.
 */
#[StripeEvent('invoice.payment_failed')]
#[StripeEvent('invoice.payment_action_required')]
class StartDunningOnInvoicePaymentFailed extends StripeWebhookHandler
{
    public int $tries = 4;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(private readonly StartDunningSequence $startDunning) {}

    public function handle(WebhookEventDTO $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->relatedObject();

        $user = User::where('stripe_id', $invoice->customer)->firstOrFail();

        ($this->startDunning)(
            user: $user,
            invoiceId: $invoice->id,
            attemptCount: $invoice->attempt_count ?? 1,
            nextAttemptAt: isset($invoice->next_payment_attempt)
                ? \DateTimeImmutable::createFromFormat('U', (string) $invoice->next_payment_attempt) ?: null
                : null,
        );
    }
}
