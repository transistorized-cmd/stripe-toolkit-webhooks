<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secrets
    |--------------------------------------------------------------------------
    |
    | Map a config key to a Stripe webhook signing secret. The "default" key
    | is used when the route is registered without a {configKey} placeholder.
    | Add additional entries for Stripe Connect endpoints or any setup that
    | exposes more than one webhook URL.
    |
    */

    'webhook_secrets' => [
        'default' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route defaults
    |--------------------------------------------------------------------------
    */

    'route' => [
        'path' => 'stripe/webhook',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection and queue name used to dispatch the asynchronous processing
    | of stored webhook calls. Run a dedicated worker for low-latency
    | handling: `php artisan queue:work --queue=stripe-webhooks`.
    |
    */

    'queue' => [
        'connection' => env('STRIPE_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('STRIPE_QUEUE_NAME', 'stripe-webhooks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database table names
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'webhook_calls' => 'stripe_webhook_calls',
        'handler_runs' => 'stripe_webhook_handler_runs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long, in days, to keep rows of each terminal status before they
    | are eligible for pruning. Set any value to null to retain forever.
    | Pruning itself is performed by `php artisan stripe-webhooks:prune`
    | (Pro tier) or by your own scheduler.
    |
    */

    'retention' => [
        'processed_days' => 90,
        'failed_days' => 365,
        'dead_letter_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature tolerance (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum acceptable difference between the timestamp on the Stripe
    | signature header and the local clock. Stripe's default is 300s.
    |
    */

    'tolerance' => 300,

    /*
    |--------------------------------------------------------------------------
    | Handlers
    |--------------------------------------------------------------------------
    |
    | Map Stripe event types to one or more handler classes. Each handler
    | runs in its own queued job and retries independently.
    |
    | You may also enable attribute discovery (below) and let the kit find
    | handlers annotated with #[StripeEvent('event.type')] under a given
    | path.
    |
    */

    'handlers' => [
        // 'invoice.payment_failed' => [
        //     \App\Stripe\Handlers\NotifyCustomerOfFailedPayment::class,
        // ],
    ],

    'discover_attributes' => true,
    'discover_path' => null,
    // ↑ defaults to app_path('Stripe/Handlers') at runtime if null.

    /*
    |--------------------------------------------------------------------------
    | Debug inspector
    |--------------------------------------------------------------------------
    |
    | Lightweight read-only Blade page that lists recent webhook calls and
    | their handler runs, rendered at the path below. Useful for local
    | development; auto-disabled in production unless explicitly enabled.
    |
    | The Pro tier ships a full Filament admin with filters, replay, and
    | dead-letter inspection; this page only shows what arrived.
    |
    */

    'debug' => [
        'enabled' => env('STRIPE_WEBHOOKS_DEBUG', null),
        'path' => env('STRIPE_WEBHOOKS_DEBUG_PATH', 'stripe-webhooks-debug'),
        'middleware' => ['web'],
        'per_page' => 25,
        'auto_refresh_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pro
    |--------------------------------------------------------------------------
    |
    | Settings consumed by the optional Pro package. Safe to leave as-is if
    | you only use the free core.
    |
    */

    'pro' => [
        'license_key' => env('STRIPE_WEBHOOKS_PRO_LICENSE'),
        'dead_letter_alert_threshold' => 10,
        'dead_letter_alert_channels' => ['slack'],
    ],

];
