# Thin events (V2 Event Destinations)

Stripe's V2 webhook format ships a minimal envelope with `related_object`
metadata only — the actual resource (PaymentIntent, Customer, …) must
be fetched separately. The kit hides this difference behind the same
`WebhookEventDTO`, so handlers don't fork by source format.

## Detection

The kit inspects `payload.object`:
- `"event"` → snapshot path (`\Stripe\Webhook::constructEvent`)
- `"v2.core.event"` → thin path (`\Stripe\WebhookSignature::verifyHeader`
  + `\Stripe\V2\Event::constructFrom`)
- anything else → `UnrecognizedPayloadException` (HTTP 400)

Detection happens before signature verification, which is safe: an
attacker who picks the wrong path just causes verification to fail.

## SDK requirements

Thin event support requires `stripe/stripe-php` ^17. The kit also runs
on v15 and v16 — but if a v2 payload arrives there, you get an
`UnsupportedSdkVersionException` (HTTP 500) instead of a silent failure.
Upgrade the SDK to handle thin events.

## Lazy hydration

```php
public function handle(WebhookEventDTO $event): void
{
    // No API call yet.
    $type = $event->normalizedType();
    $accountId = $event->accountId();

    if ($type === 'customer.created') {
        // First call to relatedObject() performs the API fetch.
        // Subsequent calls hit the in-DTO cache.
        /** @var \Stripe\Customer $customer */
        $customer = $event->relatedObject();
        // ...
    }
}
```

The kit caches the fetched object inside the DTO instance so a handler
that accesses `relatedObject()` ten times does ONE network call.

## API key needed

The lazy fetch uses the Stripe SDK's standard mechanism, which requires
`\Stripe\Stripe::setApiKey()` to be set somewhere in your app boot
(Cashier sets it, or you can set it in your `AppServiceProvider`). If
not set, the SDK throws — surface this to your error tracker so it
isn't silent.

## When the SDK ships a typed subclass

Stripe's SDK ships generated subclasses for some thin event types
(`V1BillingMeterErrorReportTriggeredEvent`, etc.). The kit picks the
most specific class via `\Stripe\Util\EventTypes::thinEventMapping` and
calls `fetchRelatedObject()` on it when defined. For unmapped types it
falls back to `\Stripe\V2\Event` and reads the `related_object` stub
directly.

The mapping is small today (3 entries in v17.6) but Stripe expands it
each release; the kit picks up new mappings automatically with no code
changes on your side.

## Storage

Thin events are recorded with `source = 'thin'` on the `WebhookCall`
row. The full envelope is in `payload`, and `related_object_id` is the
id Stripe sent (e.g. `cus_…`). If you want to replay a thin event later,
the kit's `RunStripeHandler::rebuildDTO` reconstructs the V2 event from
the stored payload and lazy-fetches the related object as if it were
the original delivery.
