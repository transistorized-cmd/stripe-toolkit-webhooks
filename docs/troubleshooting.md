# Troubleshooting

## 419 on Stripe POSTs

**Symptom**: every Stripe webhook returns 419.

**Cause**: the route lives in `routes/web.php` and Laravel applied CSRF
verification.

**Fix**: in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'stripe/webhook',
        'stripe/webhook/*',
    ]);
})
```

Or move the route to `routes/api.php` (which uses the `api` middleware
group, no CSRF). Run `php artisan install:api` to bootstrap that file
on Laravel 12+.

## "Stripe-Signature header is missing"

**Symptom**: HTTP 400 with the above message on every POST.

**Cause**: a reverse proxy (Cloudflare, ngrok, etc.) is stripping or
renaming the header.

**Fix**: confirm the header arrives at PHP. Cloudflare and most CDNs
pass it through unchanged. If you're behind a corporate proxy, check
its header allowlist.

## "No webhook secret configured for key [default]"

**Symptom**: HTTP 400 on every POST after install.

**Cause**: `STRIPE_WEBHOOK_SECRET` is empty in `.env`.

**Fix**: add the `whsec_…` value from the Stripe Dashboard or `stripe
listen`. After editing `.env`, run `php artisan config:clear` if you're
in a cached config.

## "Stripe signature verification failed: Timestamp outside the tolerance zone"

**Symptom**: HTTP 400 with the timestamp message.

**Cause**: the server clock is more than 5 minutes off from Stripe's.

**Fix**: sync the clock (`sudo timedatectl set-ntp on` on Linux). If
you genuinely need a wider window:

```php
// config/stripe-webhooks.php
'tolerance' => 600, // seconds
```

## Handler is registered but doesn't run

Check, in order:

1. The handler class lives under `discover_path` (default
   `app_path('Stripe/Handlers')`).
2. The class is concrete (`abstract class` is skipped by design).
3. The `#[StripeEvent('…')]` attribute is on the class itself, not on
   a method.
4. The event type matches — try `php artisan tinker` then
   `app(\TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery::class)->build()`
   to dump the resolved map.
5. The queue worker is running and listening on the right queue
   (default `stripe-webhooks`).

For thin events, remember that `payment_intent.succeeded` and
`v1.payment_intent.succeeded` route to the same handler thanks to
normalization — but only if the handler is registered for the
canonical (non-prefixed) form.

## `php artisan serve` hangs on the form trigger

**Symptom**: the debug inspector's form trigger times out after 10s.

**Cause**: PHP's built-in dev server is single-threaded; the kit POSTs
to itself which deadlocks.

**Fix**:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

`--no-reload` is mandatory — Laravel ignores worker count without it.

## "Thin events require stripe/stripe-php ^17"

**Symptom**: HTTP 500 when a thin (`v2.core.event`) payload arrives.

**Cause**: your composer-locked `stripe/stripe-php` is older than 17.

**Fix**:

```bash
composer require "stripe/stripe-php:^17.0"
```

The kit otherwise runs on v15/v16 — only thin events require v17.

## `WebhookHandlerRun.last_error` is empty for a dead-lettered run

**Cause**: the handler used `exit()` or `die()` instead of throwing.
PHP exits don't surface to our `try/catch`.

**Fix**: throw exceptions; never `exit` from a handler. The kit
categorizes by exception type, so `\DomainException` becomes
`business_rule`, `\Illuminate\Database\Eloquent\ModelNotFoundException`
becomes `not_found`, and so on.

## Job retries don't honour my `$backoff`

**Cause**: the `sync` queue driver runs jobs inline and ignores
`release($delay)`. Backoff only applies on real queues (`database`,
`redis`, etc.).

**Fix**: switch to a real queue driver in production. For development,
sync is fine — failures simply don't retry.

## Tables grow unbounded in production

**Cause**: nothing is pruning old `WebhookCall` rows.

**Fix**: schedule the prune command in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('stripe-webhooks:prune')->dailyAt('03:00');
```

Retention is configured under `stripe-webhooks.retention` — set
`processed_days`, `failed_days`, `dead_letter_days` to taste. `null`
means "retain forever".
