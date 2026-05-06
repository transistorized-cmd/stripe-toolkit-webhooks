# Multi-secret routing & Stripe Connect

Stripe Connect platforms typically expose multiple webhook endpoints —
one for the platform itself, one per Connected Account, sometimes more.
Each has its own signing secret. The kit handles this via two
mechanisms that are complementary, not alternatives:

1. **Multi-secret routing** — different URLs verify against different
   secrets.
2. **`accountId()` on the DTO** — a single endpoint surfaces which
   Connected Account each event came from.

## Multi-secret routing

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
    'climate'        => env('STRIPE_WEBHOOK_SECRET_CLIMATE'),
],
```

A POST to `/stripe/webhook/platform` verifies against the
`platform` secret. The `configKey` is recorded on the `WebhookCall` row,
so you can filter the inspector or query history by source.

## `accountId()` on the DTO

When an event originates from a Connected Account, Stripe includes the
account id in the payload (`event.account` for snapshot, the
`notification.context` field for thin). The kit extracts this and
exposes it:

```php
public function handle(WebhookEventDTO $event): void
{
    $intent = $event->relatedObject();
    $accountId = $event->accountId();   // 'acct_…' or null

    if ($accountId !== null) {
        $tenant = Tenant::where('stripe_account_id', $accountId)->firstOrFail();
        // proceed with tenant context
    }
}
```

## When to use which

| Scenario | Use |
|---|---|
| Each Connected Account has its own webhook URL in the dashboard | multi-secret routing — one route per account, secret per account |
| One webhook URL receives events from many accounts (typical Connect setup) | `accountId()` on the DTO |
| Both | both — the route param identifies the secret for HMAC verification, the accountId identifies the tenant for business logic |

## Where it's stored

The kit records `config_key` (which route param matched) and
`related_object_id` (event-specific) on every row. The Connected
Account id from the payload is available on the DTO at handler time but
isn't a column today. Open an issue if you need it queryable.
