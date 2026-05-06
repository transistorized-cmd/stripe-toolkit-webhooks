# Examples

Realistic handler implementations for the most common Stripe events. Each
file is self-contained and assumes you have the kit installed and a
sensible domain model (`Order`, `Subscription`, `User` with `stripe_id`).

Drop the file into `app/Stripe/Handlers/` and the kit auto-discovers it
through the `#[StripeEvent]` attribute. Type normalization means the
same handler matches both snapshot (`payment_intent.succeeded`) and thin
(`v1.payment_intent.succeeded`) variants — you don't need to register
twice when migrating to Event Destinations.

| File | Use case |
|---|---|
| `FulfillOrderOnPaymentIntentSucceeded.php` | Marks an order paid and sends the receipt |
| `StartDunningOnInvoicePaymentFailed.php` | Kicks off a multi-step dunning sequence |
| `ReconcileChargeRefunded.php` | Updates accounting when a charge is refunded |
| `RevokeAccessOnSubscriptionDeleted.php` | Removes access when a subscription ends |

Each example explains its retry strategy, idempotency assumptions, and
how it fails (transient vs business rule vs schema mismatch — categories
the kit records to `webhook_handler_runs.last_error_category`).
