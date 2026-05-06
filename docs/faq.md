# FAQ

## Why "Stripe Toolkit"?

This package is the first module of *The Complete Stripe Toolkit for
Laravel* — a family of focused modules covering different facets of
Stripe integration (webhooks today; refunds, Connect, dunning,
reconciliation as the toolkit expands). The webhooks module is
self-contained and useful on its own.

## Does it replace Cashier?

No. Cashier handles subscriptions and customer billing flows; this kit
handles the webhook delivery layer underneath. They're complementary —
many production apps run both. The kit is webhook-format-agnostic, so
it works whether you're on Cashier, building a marketplace with
Stripe Connect, or doing one-off PaymentIntents.

## Does it replace `spatie/laravel-stripe-webhooks`?

That's the intent for new projects. For existing Spatie installs, the
kit can run alongside during migration; see the
[migration guide](/migrating-from-spatie). The kit adds per-handler
retries, dead-letter tracking, thin event support, and a typed DTO
contract that Spatie doesn't have.

## What about the Pro tier?

A Pro module (`transistorized-cmd/stripe-toolkit-webhooks-pro`) ships
separately with:
- Filament v4 admin panel (replay, DLQ inspector, batch actions, filters)
- `stripe-webhooks:replay` command + UI action
- Notification rules (Slack, email, etc.) for DLQ thresholds
- Test factories: a Laravel-native equivalent to `stripe trigger`
- Zero-downtime signing-secret rotation helper
- Pulse + Telescope cards

Pro is paid (Lemon Squeezy). The free core works fully without it. Pro
is gated on commercial validation — see the project's GitHub for
status.

## Can I use it with Lumen / Symfony / a non-Laravel app?

Not in this form. The kit depends on Laravel's queue, routing, Eloquent,
and event subsystems. You can pull the `WebhookEventDTO` contract and
the snapshot/thin adapters as a starting point for a Symfony port, but
the wiring is Laravel-specific.

## Does it support `stripe-php` v15 / v16?

Yes. Snapshot events work on all three (^15, ^16, ^17). Thin events
require ^17 because that's where `\Stripe\V2\Event` lives. The kit
detects this at runtime and surfaces `UnsupportedSdkVersionException`
if a thin payload arrives without the v17 SDK present.

## How do I test handlers without a Stripe account?

The debug inspector's form trigger signs payloads server-side and
POSTs them to your own endpoint — no Stripe account needed. For more
realistic flows, install the Stripe CLI (`stripe listen` + `stripe
trigger`); it's free and gives you Stripe's exact wire format.

In Pest tests, use `tests/Support/SignedPayload` (shipped with the kit)
to construct signed bodies + headers programmatically.

## Why does the handler need to be idempotent if Stripe sends event_id?

The kit dedupes at the HTTP boundary — a re-delivery of the same
`event.id` returns 200 without re-running handlers. **But** Stripe's
own retry policy will re-deliver an event multiple times if your
endpoint repeatedly fails (slow processing, downtime, etc). And your
operator may manually replay events from the Pro inspector. So in
practice your handler will sometimes run more than once on the same
event_id. Treat external side effects (sending email, calling APIs)
with care; pure DB updates are usually naturally idempotent.

## How do I know which version of the SDK I'm running?

```bash
composer show stripe/stripe-php
```

For thin event support, look for `versions : * v17.x.y`. The mapping
table `\Stripe\Util\EventTypes::thinEventMapping` shows which event
types the SDK has typed subclasses for (only 3 in v17.6 — Stripe adds
more each release).

## Can I customize the queue connection / queue name?

Yes:

```
STRIPE_QUEUE_CONNECTION=redis
STRIPE_QUEUE_NAME=high-priority
```

Or set `stripe-webhooks.queue.connection` / `.name` directly.

## Can I disable thin event support?

Set `stripe/stripe-php:^15.0` (or `^16.0`) in your composer.json. The
kit will then refuse thin payloads with a clear 500 — useful if you
want to roll thin out gradually and reject misrouted payloads
explicitly. Once you're ready, upgrade the SDK and they'll start
working with no kit code change.

## Where do I report bugs?

[GitHub Issues](https://github.com/transistorized-cmd/stripe-toolkit-webhooks/issues).
For security issues, email rather than file publicly.
