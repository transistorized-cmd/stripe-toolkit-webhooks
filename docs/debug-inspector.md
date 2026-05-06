# Debug inspector

The kit ships a read-only Blade UI at `/stripe-webhooks-debug` for
local development. It lists incoming webhook calls in real time, shows
handler runs and stack traces, and includes a small form trigger to
send signed test events to your own endpoint.

## Enabling

Default behaviour: ON in `local`/`testing`, OFF in `production`.

Override with an env var:

```
STRIPE_WEBHOOKS_DEBUG=true
```

Or in config:

```php
// config/stripe-webhooks.php
'debug' => [
    'enabled' => true,
    'path' => 'stripe-webhooks-debug',  // change if you don't want the default URL
    'auto_refresh_seconds' => 5,
    'per_page' => 25,
],
```

## What you see

**Index** (`/stripe-webhooks-debug`):
- Counters per status (received / processing / processed / failed / dead_letter)
- Filters by status, type, and config_key
- A live-updating table with badges (green for processed, red for dead-letter, etc.)
- An iframe form at the top to send test events

**Detail** (`/stripe-webhooks-debug/{id}`):
- All metadata for the call
- Handler runs (one row per dispatched handler) with status, attempts, and expandable error details
- The full payload pretty-printed

The index uses fetch-and-replace for live updates rather than meta
refresh, so the form iframe state is preserved between ticks.

## The form trigger

Send signed test events without leaving the browser. Fields:

- **event type** — preset list of common types or paste a custom one
- **config key** — match the `webhook_secrets` map; tests multi-secret routing
- **force event_id** — replay idempotency by reusing an id
- **force object_id** — pin `pi_…`, `ch_…`, etc.
- **amount / currency / customer** — quick payload tweaks
- **timestamp skew** — push the signature timestamp out of tolerance to
  test rejection
- **tamper signature** — sign with a known-bad secret to test rejection
- **custom data.object JSON** — paste arbitrary JSON; overrides the
  generated object so you can replicate any Stripe shape

The form posts to a kit-internal endpoint (`/stripe-webhooks-debug/_send`)
which signs server-side with the configured secret and POSTs to your
own webhook route as if Stripe sent it. The result panel shows HTTP
status + body, with badges colour-coded by outcome.

Form values persist in `localStorage` so they survive page reloads —
except in "duplicate from id" mode, where the source event's payload
takes priority.

## Loopback gotcha

`php artisan serve` is single-threaded by default. The form trigger
makes the app POST to itself, which deadlocks single-thread setups. Run
the dev server with workers:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

`--no-reload` is required — without it Laravel ignores the worker count
and falls back to a single process.

Production is unaffected (Nginx + PHP-FPM is always concurrent).

## What the inspector does NOT do

- **Replay**: re-running handlers on a stored event is a Pro feature.
- **Mark as resolved**: same — Pro.
- **Bulk operations**: same — Pro.

The inspector is intentionally read-only and dev-mode-only. The Pro
module ships a Filament v4 panel with replay actions, batch operations,
and DLQ inspection.
