# Bulletproof Stripe Webhooks for Laravel

[![Tests](https://github.com/transistorized-cmd/stripe-toolkit-webhooks/actions/workflows/tests.yml/badge.svg)](https://github.com/transistorized-cmd/stripe-toolkit-webhooks/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2--8.4-777BB4)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11--13-FF2D20)](https://laravel.com)
[![Stripe SDK](https://img.shields.io/badge/Stripe%20SDK-15--17-635BFF)](https://github.com/stripe/stripe-php)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow)](LICENSE.md)

> First module of **The Complete Stripe Toolkit for Laravel**.
>
> The webhook reliability layer your future-self wishes you had shipped
> on day one: idempotent, queue-backed, observable — for both classic
> snapshot webhooks and the new Event Destinations (thin events) — under
> a single typed DTO.

> **Status:** `v1.0.0-rc.1`. Free core feature-complete, 51 Pest tests
> green, used in production by [TicketFairy](https://ticketfairy.com).
> Pro module ships separately.

---

## A taste

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

        Order::where('stripe_pi', $intent->id)
            ->firstOrFail()
            ->markPaid($intent->amount, $intent->currency);
    }
}
```

That's the whole thing. Drop the file under `app/Stripe/Handlers/`, and
the kit auto-discovers it via the attribute, persists every incoming
webhook keyed by `event.id`, queues your handler with its own retry
schedule, and dead-letters cleanly when retries run out.

The same handler matches `payment_intent.succeeded` *and*
`v1.payment_intent.succeeded` — when you migrate to Event Destinations,
your business code doesn't change.

---

## Who this is for

You'll get the most out of this package if you fit one of these:

- **You run a Laravel SaaS in production** and you've already lived
  through a webhook outage — silent failures, double-processed events,
  duplicate emails to customers, accounting that doesn't reconcile.
  You want a layer that makes the next integration boring.
- **You're rolling out Stripe Connect or Event Destinations** and you
  need both the legacy snapshot webhooks and the new V2 thin events
  flowing through the same handlers without forking your code.
- **You're a tech lead at a payments-heavy product** and you'd rather
  install a vetted package than re-derive idempotency, retry
  semantics, and dead-letter tracking from first principles for the
  fourth time.

This is **not** the easiest entry point if you're brand new to Stripe
or Laravel queues — the kit assumes you know why `event.id` matters
and what backoff means. If that's you, ship `spatie/laravel-stripe-webhooks`
first, hit a wall, then come back.

---

## What it does

The pipeline, from POST to handler:

```
Stripe POST /stripe/webhook
    │
    ▼
[Controller]
  1. Verify signature (HMAC SHA256, constant-time, tolerance configurable)
  2. Detect format (snapshot vs thin) by inspecting payload.object
  3. Persist row in stripe_webhook_calls keyed by event.id (UNIQUE)
       └─ if event.id already PROCESSED → 200 "duplicate", no dispatch
       └─ if event.id already RECEIVED  → 200 "in_progress" (race winner owns it)
  4. Fire WebhookReceived event
  5. Dispatch ProcessStripeWebhook to queue
  6. Return 200 (target: <100ms)
    │
    ▼
[Queue worker · ProcessStripeWebhook]
  Resolve handler classes (config map ∪ #[StripeEvent] attributes)
  For each → dispatch RunStripeHandler to queue
    │
    ▼
[Queue worker · RunStripeHandler] (one job per handler per event)
  Reconstruct typed WebhookEventDTO
  Try { handler->handle($event); mark processed; }
  Catch { categorize error; release with backoff; }
  After $tries failures → mark dead_letter, fire WebhookDeadLettered
```

What you get on top of that:

- **Idempotency at the event level** — same `event.id` twice is a no-op
  for handlers, including under concurrent delivery (race-tested).
- **Per-handler retries** — one slow handler doesn't poison the rest;
  each is its own job with its own `$tries`/`$backoff`.
- **Native typed Stripe objects** — `relatedObject()` returns
  `\Stripe\PaymentIntent`, `\Stripe\Charge`, etc., not arrays.
- **Type normalization** — `payment_intent.succeeded` and
  `v1.payment_intent.succeeded` route to the same handler; the
  prefix-stripping happens automatically.
- **Multi-secret routing** — `/stripe/webhook/{configKey}` lets you
  carry multiple Stripe webhook endpoints (Connect, separate mode,
  etc.) on a single Laravel app.
- **Connect-aware** — `accountId()` on the DTO surfaces the connected
  account id from the payload (snapshot or thin).
- **Read-only debug inspector** at `/stripe-webhooks-debug` (dev-mode)
  with a built-in form trigger to send signed test events to your own
  endpoint without leaving the browser.
- **Three artisan commands**: `install`, `prune`, `migrate-from-spatie`.

---

## Why this and not…

| | `spatie/laravel-stripe-webhooks` | `laravel/cashier` | This kit |
|---|:-:|:-:|:-:|
| Persists the call before processing | yes | no | **yes** |
| Idempotency on `event.id` | partial | no | **event-level + per-handler** |
| Per-handler retries with own backoff | no | no | **yes** |
| Dead-letter tracking with error category | no | no | **yes** |
| Adapter for thin events (Event Destinations) | no | no | **yes** |
| Type normalization (snapshot ↔ thin) | no | n/a | **yes** |
| `relatedObject()` returns native Stripe types | no | n/a | **yes** |
| `accountId()` for Connect | no | partial | **yes** |
| Read-only debug UI | no | no | **yes** |
| Dependency on subscription model | none | **required** | none |
| Scope | webhook plumbing | subscription billing | webhook plumbing + observability |

`spatie/laravel-stripe-webhooks` is the dominant package by downloads
(3M+) — it works, it's stable, it persists. The kit is what you reach
for when you outgrow it: when you want per-handler reliability and
observability, when you need thin events, when you're tired of
forking your handler logic by event-type version. There's a
[migration command](#artisan-commands) to import your existing Spatie
history.

---

## Install

```bash
composer require transistorized-cmd/stripe-toolkit-webhooks
php artisan stripe-webhooks:install
php artisan migrate
```

`stripe-webhooks:install` does three things:
1. Publishes `config/stripe-webhooks.php`
2. Publishes the two migrations (`stripe_webhook_calls` and
   `stripe_webhook_handler_runs`)
3. Scaffolds a sample handler at
   `app/Stripe/Handlers/HandlePaymentIntentSucceeded.php` so you have
   something to edit immediately

Add the signing secret to `.env`:

```
STRIPE_WEBHOOK_SECRET=whsec_…
```

Register the route:

```php
// routes/web.php
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;

StripeWebhook::route('stripe/webhook');
```

If your route lives in `routes/web.php` (rather than `routes/api.php`),
exclude it from CSRF in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'stripe/webhook',
        'stripe/webhook/*',
    ]);
})
```

Run a queue worker:

```bash
php artisan queue:work --queue=stripe-webhooks
```

Done. Your endpoint is live.

---

## Your first handler

Two ways to register, both work, you can mix them.

**Attribute discovery** — recommended, autoloaded from
`app/Stripe/Handlers/` (path is configurable):

```php
#[StripeEvent('invoice.payment_failed')]
#[StripeEvent('invoice.payment_action_required')]   // attribute is repeatable
class StartDunning extends StripeWebhookHandler
{
    public function handle(WebhookEventDTO $event): void
    {
        /** @var \Stripe\Invoice $invoice */
        $invoice = $event->relatedObject();
        // …
    }
}
```

**Config map** — explicit, useful when registering closures or
deferring class loading:

```php
// config/stripe-webhooks.php
'handlers' => [
    'invoice.payment_failed' => [
        \App\Stripe\Handlers\NotifyCustomer::class,
        \App\Stripe\Handlers\StartDunning::class,
    ],
],
```

Whichever you pick, the DTO contract is identical:

| Method | Returns |
|---|---|
| `id()` | `string` — Stripe `evt_…` |
| `type()` | `string` — raw type, e.g. `payment_intent.succeeded` |
| `normalizedType()` | `string` — version-prefix-stripped |
| `createdAt()` | `\DateTimeImmutable` |
| `apiVersion()` | `?string` — present on snapshot, null on thin |
| `accountId()` | `?string` — Connected Account id, when applicable |
| `livemode()` | `bool` |
| `sourceFormat()` | `EventSource::Snapshot \| EventSource::Thin` |
| `relatedObject()` | `mixed` — typed `\Stripe\…` instance (lazy on thin) |
| `rawPayload()` | `string` — what Stripe POSTed, byte-for-byte |

See [`examples/`](examples) for four production-shaped handlers covering
fulfillment, dunning, refund reconciliation, and access revocation.

---

## Multi-secret routing & Stripe Connect

Two complementary mechanisms for Connect:

### Per-endpoint secrets

```php
// routes/web.php
StripeWebhook::route('stripe/webhook/{configKey}');
```

```php
// config/stripe-webhooks.php
'webhook_secrets' => [
    'default'        => env('STRIPE_WEBHOOK_SECRET'),
    'platform'       => env('STRIPE_WEBHOOK_SECRET_PLATFORM'),
    'connect_v2'     => env('STRIPE_WEBHOOK_SECRET_CONNECT_V2'),
],
```

A POST to `/stripe/webhook/platform` verifies against the `platform`
secret. The `configKey` is recorded on every `WebhookCall`, so you can
filter the inspector or query history by source.

### `accountId()` on the DTO

When a single endpoint receives events from many connected accounts
(typical Connect setup), the kit extracts the account id and exposes
it on the DTO:

```php
public function handle(WebhookEventDTO $event): void
{
    if ($accountId = $event->accountId()) {
        $tenant = Tenant::where('stripe_account_id', $accountId)->firstOrFail();
        // proceed with tenant context
    }
}
```

The two are not alternatives — use both: the route param identifies the
secret for HMAC verification, the accountId identifies the tenant.

---

## Snapshot + thin events

Stripe sends two formats:

- **Snapshot** (`/v1/webhooks` legacy): full `event.data.object` in the
  payload.
- **Thin** (Event Destinations / V2, GA Oct 2024): minimal envelope
  with `related_object` metadata only — the actual resource is fetched
  on demand.

You don't have to think about this. The kit:

1. Detects format from `payload.object` (`event` vs `v2.core.event`)
   before signature verification.
2. Verifies with the right code path (`Stripe\Webhook::constructEvent`
   vs `Stripe\WebhookSignature::verifyHeader`).
3. Hydrates a typed wrapper either way (`SnapshotEventDTO` or
   `ThinEventDTO`).
4. Returns the same contract from `relatedObject()` — typed Stripe
   objects, instant for snapshot, lazy-fetched + cached for thin.

Type normalization means handlers registered as `payment_intent.succeeded`
match `v1.payment_intent.succeeded` automatically. Migrate to Event
Destinations whenever Stripe forces you; your code doesn't change.

`stripe/stripe-php` `^15`/`^16` are supported, but thin event support
needs `^17`. If a v2 payload arrives on an older SDK, you get a clean
500 with an explicit `UnsupportedSdkVersionException` rather than
silent failure.

---

## Debug inspector

In `local`/`testing` environments the kit exposes a read-only Blade UI
at `/stripe-webhooks-debug`:

- Live-updating table of recent calls with status counters and filters
- Detail view per call with metadata, handler runs, error stack traces,
  and pretty-printed payload
- Embedded form trigger that signs payloads server-side and POSTs them
  to your own endpoint — useful for replicating Stripe's exact wire
  format during development
- "Duplicate this event" links from rows and detail page

Auto-disabled in `production` unless you explicitly opt in:

```
STRIPE_WEBHOOKS_DEBUG=true
STRIPE_WEBHOOKS_DEBUG_PATH=/internal/webhooks   # optional: change the URL
```

The form trigger makes the app POST to itself. PHP's built-in dev
server is single-threaded by default; run it with workers:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

---

## Testing helpers

Sign payloads programmatically in your test suite:

```php
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

$body = SignedPayload::body($eventArray);
$header = SignedPayload::header($body, 'whsec_test_default');

$this->call('POST', '/stripe/webhook', [], [], [], [
    'HTTP_STRIPE_SIGNATURE' => $header,
    'CONTENT_TYPE' => 'application/json',
], $body)->assertOk();
```

Pre-built fixtures cover snapshot (with optional Connect account) and
thin event payloads:

```php
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;

$payload = Fixtures::snapshotPaymentIntentSucceeded(accountId: 'acct_…');
$thinPayload = Fixtures::thinV1CustomerCreated();
```

A richer `WebhookFactory` API — fluent builders for every event type, à
la `stripe trigger` but Laravel-native — ships in the Pro module.

---

## Laravel events to hook

```php
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookDeadLettered;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookHandlerFailed;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookProcessed;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookReceived;

Event::listen(WebhookDeadLettered::class, function (WebhookDeadLettered $e) {
    Slack::send("Stripe webhook DLQ: {$e->webhookCall->stripe_event_id}");
});
```

| Event | Fires when |
|---|---|
| `WebhookReceived` | Persisted to DB, before queue dispatch |
| `WebhookProcessed` | All handlers for an event finished OK |
| `WebhookHandlerFailed` | A handler attempt failed (may still retry) |
| `WebhookDeadLettered` | A handler exhausted retries — DLQ entry |

---

## Artisan commands

| Command | What it does |
|---|---|
| `stripe-webhooks:install` | Publishes config + migrations + sample handler stub |
| `stripe-webhooks:prune` | Deletes rows past the retention horizon (`--dry-run`, `--status=` filters) |
| `stripe-webhooks:migrate-from-spatie` | Imports history from a `spatie/laravel-stripe-webhooks` install (`--dry-run`, `--source-table=`, `--source-name=`, `--batch-size=`, `--since=`) |

Schedule the prune in `routes/console.php`:

```php
Schedule::command('stripe-webhooks:prune')->dailyAt('03:00');
```

Retention is configurable per status (see below).

---

## Configuration reference

`config/stripe-webhooks.php` after `stripe-webhooks:install`:

```php
return [
    'webhook_secrets' => [
        'default' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'route' => [
        'path' => 'stripe/webhook',
        'middleware' => ['api'],
    ],

    'queue' => [
        'connection' => env('STRIPE_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('STRIPE_QUEUE_NAME', 'stripe-webhooks'),
    ],

    'tables' => [
        'webhook_calls' => 'stripe_webhook_calls',
        'handler_runs' => 'stripe_webhook_handler_runs',
    ],

    'retention' => [
        'processed_days' => 90,         // null = retain forever
        'failed_days' => 365,
        'dead_letter_days' => null,     // keep DLQ forever by default
    ],

    'tolerance' => 300,                  // signature timestamp tolerance (s)

    'handlers' => [
        // 'invoice.payment_failed' => [\App\Stripe\Handlers\StartDunning::class],
    ],

    'discover_attributes' => true,
    'discover_path' => null,             // null → app_path('Stripe/Handlers')

    'debug' => [
        'enabled' => env('STRIPE_WEBHOOKS_DEBUG', null),  // null = auto (on outside production)
        'path' => env('STRIPE_WEBHOOKS_DEBUG_PATH', 'stripe-webhooks-debug'),
        'middleware' => ['web'],
        'per_page' => 25,
        'auto_refresh_seconds' => 5,
    ],
];
```

---

## Compatibility matrix

CI runs three combinations:

| PHP | Laravel | Stripe SDK |
|---|---|---|
| 8.2 | 11.x | ^15.0 |
| 8.3 | 12.x | ^16.0 |
| 8.4 | 13.x | ^17.0 |

Plus a static-analysis job (Pint preset Laravel + PHPStan / Larastan
level 5) that runs before the matrix.

Filament v4 is an optional `suggest` for the Pro module's admin panel.
The free core doesn't depend on it.

---

## Documentation

This README is the executive summary. The full guide lives in
[`docs/`](docs):

- [Installation](docs/installation.md)
- [Writing handlers](docs/handlers.md)
- [Multi-secret · Connect](docs/multi-secret-connect.md)
- [Thin events](docs/thin-events.md)
- [Debug inspector](docs/debug-inspector.md)
- [Migrating from Spatie](docs/migrating-from-spatie.md)
- [Troubleshooting](docs/troubleshooting.md)
- [FAQ](docs/faq.md)

The site is VitePress; `cd docs && npm install && npm run docs:dev` to
preview locally.

---

## Roadmap & Pro module

This module focuses on the **infrastructure** of Stripe webhooks. The
companion Pro module (`transistorized-cmd/stripe-toolkit-webhooks-pro`)
adds the operator-facing parts:

- Filament v4 admin panel: replay actions, DLQ inspector, batch
  operations, advanced filters
- `stripe-webhooks:replay` CLI command
- DLQ alert notifications (Slack, email, custom channels)
- Test factories — Laravel-native equivalent to `stripe trigger`
- Zero-downtime signing-secret rotation helper
- Pulse + Telescope cards

Pro is paid (Lemon Squeezy). The free core works fully without it.

The wider picture — refunds, disputes, Connect platform mechanics,
dunning flows, reconciliation, audit trails — is the subject of an
upcoming book in *The Complete Stripe Toolkit for Laravel* series.
[Drop your email](#) to be notified when it's ready.

---

## Contributing

Issues and pull requests welcome. Before opening a PR:

```bash
composer install
vendor/bin/pint --test         # code style
vendor/bin/phpstan analyse     # static analysis
vendor/bin/pest                # 51 tests, ~0.8s
```

Security issues: please email rather than file publicly.

---

## Credits

- Built by [Jose Luis Pellicer](https://github.com/transistorized-cmd)
  ([transistorized-cmd](https://github.com/transistorized-cmd)).
- Battle-tested on TicketFairy's production traffic.
- Inspired by the gaps in `spatie/laravel-stripe-webhooks` — which
  remains a great default for simpler use cases.

## License

MIT — see [LICENSE.md](LICENSE.md).
