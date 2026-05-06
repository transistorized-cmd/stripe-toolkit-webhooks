# Migrating from `spatie/laravel-stripe-webhooks`

`spatie/laravel-stripe-webhooks` is the dominant Stripe webhooks
package on Packagist. This kit can run alongside it during a transition
and ship with a one-shot import command for the historical table.

## What the kit gives you over Spatie

| | Spatie | This kit |
|---|---|---|
| Persists the webhook | ✅ | ✅ |
| Verifies HMAC signature | ✅ | ✅ |
| Idempotency on `event.id` | partial — at HTTP level | event-level + per-handler |
| Per-handler retries with own backoff | ❌ | ✅ |
| Dead-letter tracking with error category | ❌ | ✅ |
| Adapter for thin events (Event Destinations) | ❌ | ✅ |
| Type normalization (snapshot ↔ thin) | ❌ | ✅ |
| `relatedObject()` returns native Stripe types | ❌ | ✅ |
| `accountId()` for Connect | ❌ | ✅ |
| Read-only debug UI | ❌ | ✅ |
| PHP 8 attribute discovery | ❌ | ✅ |

## Migration path

### 1. Install side-by-side

The kit's tables (`stripe_webhook_calls`, `stripe_webhook_handler_runs`)
have different names from Spatie's (`webhook_calls`), so they coexist.
Install the kit normally:

```bash
composer require transistorized-cmd/stripe-toolkit-webhooks
php artisan stripe-webhooks:install
php artisan migrate
```

### 2. Port handlers

Spatie subscribes via Laravel events:

```php
// Spatie style
Event::listen(\Spatie\StripeWebhooks\Events\WebhookCallProcessed::class, function ($event) {
    if ($event->webhookCall->payload['type'] === 'payment_intent.succeeded') {
        // ...
    }
});
```

The kit uses dispatched handler classes:

```php
// Kit style
#[StripeEvent('payment_intent.succeeded')]
class FulfillOrder extends StripeWebhookHandler
{
    public function handle(WebhookEventDTO $event): void
    {
        $intent = $event->relatedObject();   // typed Stripe\PaymentIntent
        // ...
    }
}
```

This is usually a 1-to-1 mapping; one Spatie listener becomes one kit
handler class.

### 3. Switch the route

Replace Spatie's `Route::stripeWebhooks(...)` with the kit's:

```php
// Before
Route::stripeWebhooks('stripe/webhook');

// After
StripeWebhook::route('stripe/webhook');
```

Use the same path so Stripe doesn't need to be reconfigured. The
existing `STRIPE_WEBHOOK_SECRET` continues to work.

### 4. Import history

```bash
php artisan stripe-webhooks:migrate-from-spatie --dry-run
```

Reads from Spatie's `webhook_calls` table (configurable via
`--source-table`) where `name = 'stripe'` (configurable via
`--source-name`), decodes each payload, and creates a corresponding row
in `stripe_webhook_calls`:

- `status` = `processed` for rows without an exception, `dead_letter`
  otherwise.
- `processed_at` = the original `updated_at` for successes, `null` for
  failures.
- `received_at` = the original `created_at`.
- `config_key` = the Spatie row's `name`.

Flags:
- `--dry-run` — report counts without writing.
- `--batch-size=500` — chunk size.
- `--since=YYYY-MM-DD` — limit to recent rows.

The command is idempotent on `event_id` — running it twice doesn't
create duplicates.

### 5. Verify and uninstall Spatie

After importing, your kit table mirrors the Spatie history. Inspect via
`/stripe-webhooks-debug` (in dev) or `select * from stripe_webhook_calls`.

When you're satisfied:

```bash
composer remove spatie/laravel-stripe-webhooks
```

The Spatie `webhook_calls` table can be dropped or kept as an audit
archive — your call.

## Things the import does NOT do

- **Re-run handlers**: imported rows are marked as `processed` based on
  the Spatie-side outcome. They are NOT re-dispatched. If you need to
  re-process a particular event, use the Pro `replay` command (or
  manually via the inspector once Pro lands).
- **Migrate Listener classes**: you do that step by hand (item 2 above).
- **Touch your route**: Spatie's route stops working after the
  Composer remove; switch routes before uninstalling Spatie or you'll
  see 404s on real Stripe deliveries.
