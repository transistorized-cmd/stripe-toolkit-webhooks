# Writing handlers

A handler is a class that extends `StripeWebhookHandler` and implements
`handle(WebhookEventDTO $event)`. The kit calls it inside a queued job,
once per dispatched handler row.

## Two ways to register

### Attribute discovery (recommended)

```php
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('payment_intent.succeeded')]
class FulfillOrder extends StripeWebhookHandler
{
    public function handle(WebhookEventDTO $event): void
    {
        /** @var \Stripe\PaymentIntent $intent */
        $intent = $event->relatedObject();
        // ...
    }
}
```

The kit scans `app/Stripe/Handlers/` (configurable via `discover_path`)
on boot and registers any class with `#[StripeEvent('…')]`. The
attribute is repeatable, so a single handler can match multiple event
types.

### Config map

```php
// config/stripe-webhooks.php
'handlers' => [
    'invoice.payment_failed' => [
        \App\Stripe\Handlers\NotifyCustomer::class,
        \App\Stripe\Handlers\StartDunning::class,
    ],
],
```

The two methods compose: a handler can be discovered AND listed in
config, and gets deduplicated.

## What's inside `WebhookEventDTO`

| Method | Returns |
|---|---|
| `id()` | `string` — Stripe `evt_…` |
| `type()` | `string` — raw type, e.g. `payment_intent.succeeded` or `v1.payment_intent.succeeded` |
| `normalizedType()` | `string` — the version-prefix-stripped form |
| `createdAt()` | `\DateTimeImmutable` |
| `apiVersion()` | `?string` — present on snapshot, null on thin |
| `accountId()` | `?string` — Connected Account id when applicable |
| `livemode()` | `bool` |
| `sourceFormat()` | `EventSource::Snapshot \| EventSource::Thin` |
| `relatedObject()` | `mixed` — the typed Stripe object (PaymentIntent, Charge, …); lazy-fetched for thin |
| `rawPayload()` | `string` — what Stripe POSTed, byte-for-byte |

## Retries and backoff

```php
class FulfillOrder extends StripeWebhookHandler
{
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900, 1800, 3600];
}
```

Each retry creates a new attempt on the existing `WebhookHandlerRun` row.
After `$tries` attempts the run is marked `dead_letter` and `WebhookCall`
status follows. Handlers are retried independently — one slow handler
doesn't block the others.

## Snapshot vs thin

Stripe sends two formats:
- **Snapshot** (legacy): full `event.data.object` in the payload.
- **Thin** (Event Destinations): minimal envelope, related object
  fetched lazily.

You don't have to think about this. `relatedObject()` returns the typed
Stripe object both ways. For thin events the first call performs an API
fetch; subsequent calls hit the cache. See [Thin events](/thin-events)
for details on lazy fetching costs.

## Idempotency rules

The kit dedupes by `event.id` — the same event delivered twice
short-circuits to `200 duplicate`. But Stripe legitimately re-delivers
days later (e.g. after a network blip), and the operator may manually
replay. Treat your handler as something that may run more than once on
the same event:

- Guard at the business level when the action has external side effects
  (sending email, charging cards, calling external APIs).
- For pure DB updates that are idempotent (e.g. `markPaid()` on an order
  that's already paid), no extra guard is needed.

## Examples

The `examples/` directory in the repo ships realistic handlers with
inline commentary:
- `FulfillOrderOnPaymentIntentSucceeded.php`
- `StartDunningOnInvoicePaymentFailed.php`
- `ReconcileChargeRefunded.php`
- `RevokeAccessOnSubscriptionDeleted.php`

Drop them into `app/Stripe/Handlers/` and adapt to your domain.
