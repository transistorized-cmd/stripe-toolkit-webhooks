# Bulletproof Stripe Webhooks for Laravel

[![Tests](https://github.com/transistorized-cmd/stripe-toolkit-webhooks/actions/workflows/tests.yml/badge.svg)](https://github.com/transistorized-cmd/stripe-toolkit-webhooks/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

> First module of **The Complete Stripe Toolkit for Laravel**.
> Idempotent, queue-backed handling of Stripe webhooks — both classic
> snapshot events and the new thin events (Event Destinations / Stripe
> SDK v17+) — behind a unified DTO so your handlers don't care about the
> wire format.

---

## Why this exists

Cashier handles subscriptions synchronously. `spatie/laravel-stripe-webhooks`
persists the call but leaves business-level idempotency to you. When you
have webhooks landing in production and a handler explodes, you want:

1. Stripe to see `200 OK` immediately so it stops retrying.
2. The full payload persisted before any handler runs.
3. The same `event.id` to be safe to receive twice (Stripe *will* retry).
4. Each handler retried independently with backoff, not the whole event.
5. A single handler that matches both `payment_intent.succeeded` and
   `v1.payment_intent.succeeded` so you can roll Event Destinations
   alongside legacy webhooks without rewriting business code.

This package gives you that spine. Build your handlers on top.

## Requirements

| | |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 11.x, 12.x, 13.x |
| stripe/stripe-php | ^15, ^16, ^17 |
| Filament *(optional, Pro)* | ^4.0 |

## Install

```bash
composer require transistorized-cmd/stripe-toolkit-webhooks
php artisan stripe-webhooks:install
php artisan migrate
```

Add your Stripe webhook signing secret to `.env`:

```
STRIPE_WEBHOOK_SECRET=whsec_...
```

## Quick start

Register the route:

```php
// routes/web.php
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;

StripeWebhook::route('stripe/webhook');
```

Define a handler — either via config:

```php
// config/stripe-webhooks.php
'handlers' => [
    'invoice.payment_failed' => [
        \App\Stripe\Handlers\NotifyCustomerOfFailedPayment::class,
    ],
],
```

…or via PHP 8 attribute (auto-discovered):

```php
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

#[StripeEvent('payment_intent.succeeded')]
class FulfillOrder extends StripeWebhookHandler
{
    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function handle(WebhookEventDTO $event): void
    {
        /** @var \Stripe\PaymentIntent $intent */
        $intent = $event->relatedObject();

        $order = Order::where('stripe_pi', $intent->id)->firstOrFail();
        $order->markPaid($intent->amount, $intent->currency);
    }
}
```

`relatedObject()` returns a native typed Stripe object (`\Stripe\PaymentIntent`,
`\Stripe\Charge`, …) for snapshot events. For thin events the same call
performs a lazy API fetch the first time it's used and caches the result.

The same handler matches `payment_intent.succeeded` (snapshot) and
`v1.payment_intent.succeeded` (thin) automatically — versions are
normalized at routing time.

## Multi-secret (Connect)

```php
StripeWebhook::route('stripe/webhook/{configKey}');
```

```php
// config/stripe-webhooks.php
'webhook_secrets' => [
    'default'         => env('STRIPE_WEBHOOK_SECRET'),
    'connect_account' => env('STRIPE_WEBHOOK_SECRET_CONNECT'),
],
```

`POST /stripe/webhook/connect_account` will verify against the
`connect_account` secret. Inside the handler, `$event->accountId()`
gives you the Stripe Connected Account id when the event came from one.

## Debug inspector (local development)

In non-production environments the kit exposes a read-only inspector at
`/stripe-webhooks-debug` that lists recent webhook calls, shows handler
runs and raw payloads, and includes a small form for sending test
events to your own endpoint. Auto-disabled in `production` unless
`STRIPE_WEBHOOKS_DEBUG=true`.

## Events

The kit fires Laravel events you can hook for alerting:

| Event | When |
|---|---|
| `WebhookReceived` | Persisted to DB, before queue dispatch |
| `WebhookProcessed` | All handlers for an event finished OK |
| `WebhookHandlerFailed` | A handler attempt failed (may retry) |
| `WebhookDeadLettered` | A handler exhausted retries |

## What's next

This package focuses on the **infrastructure** of Stripe webhooks. The
broader picture — refunds, disputes, Connect platform mechanics, dunning
flows, reconciliation, audit trails — is the subject of an upcoming book
in *The Complete Stripe Toolkit for Laravel* series. Watch this repo to
get notified.

## License

MIT — see [LICENSE.md](LICENSE.md).
