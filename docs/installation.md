# Installation

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x
- `stripe/stripe-php` ^15, ^16, or ^17
- A queue driver (sync works for development)

## Install

```bash
composer require transistorized-cmd/stripe-toolkit-webhooks
php artisan stripe-webhooks:install
php artisan migrate
```

`stripe-webhooks:install` does three things:
1. Publishes `config/stripe-webhooks.php`
2. Publishes the two migrations (`stripe_webhook_calls`, `stripe_webhook_handler_runs`)
3. Scaffolds a sample handler at `app/Stripe/Handlers/HandlePaymentIntentSucceeded.php` so you have something to edit immediately

Re-run with `--force` to overwrite existing files.

## Add the secret

```bash
# .env
STRIPE_WEBHOOK_SECRET=whsec_…
```

You get this from the Stripe Dashboard when you create a webhook
endpoint or run `stripe listen` locally.

## Register the route

```php
// routes/web.php
use TransistorizedCmd\StripeToolkit\Webhooks\Facades\StripeWebhook;

StripeWebhook::route('stripe/webhook');
```

If your webhook is in `routes/web.php` you must exclude it from CSRF.
In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'stripe/webhook',
        'stripe/webhook/*',
    ]);
})
```

(If you use `routes/api.php` you can skip this — the `api` middleware
group doesn't include CSRF.)

## Run a queue worker

```bash
php artisan queue:work --queue=stripe-webhooks
```

You can override the queue name with `STRIPE_QUEUE_NAME` in `.env`. For
development the `sync` driver works; the kit dispatches handlers
inline.

## Test it

Stripe CLI is the canonical way:

```bash
stripe listen --forward-to http://localhost:8000/stripe/webhook
stripe trigger payment_intent.succeeded
```

Or visit `/stripe-webhooks-debug` (in `local`/`testing` environments)
for the [debug inspector](/debug-inspector) with its built-in send
form.
