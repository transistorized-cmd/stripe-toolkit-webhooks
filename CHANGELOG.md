# Changelog

All notable changes to `transistorized-cmd/stripe-toolkit-webhooks` will be
documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com)
and [Semantic Versioning](https://semver.org).

## [Unreleased]

### Added
- `Support\PaymentOutcome` + `Support\PaymentOutcomeState` enum
  classify every Stripe payment-bearing event into one of four states
  read directly from the payload's `data.object`:
    - **Succeeded**: money moved (or `no_payment_required`)
    - **Failed**: money didn't move, or moved and was reversed
    - **InFlight**: still processing — async bank transfer, awaiting
      3DS, auth pending capture, partially funded, under fraud review
    - **Inapplicable**: event isn't about a payment

  Coverage:
    - `charge.*` (succeeded / captured / failed / pending / expired / refunded)
    - `charge.dispute.*` (created / closed-won / closed-lost / funds_withdrawn / funds_reinstated)
    - `payment_intent.*` (succeeded / payment_failed / canceled /
      processing / requires_action / requires_confirmation / requires_capture)
    - `checkout.session.*` (completed / async_payment_succeeded /
      async_payment_failed / expired)
    - `invoice.*` (paid / payment_succeeded / payment_failed /
      payment_action_required / finalization_failed / marked_uncollectible / voided)
    - `refund.*` (top-level Refund object — succeeded / failed /
      pending / canceled)
    - `radar.early_fraud_warning.created`
    - `review.opened`, `review.closed` (approved / refunded / disputed)

  When state is failure, surfaces the matching `failure_message`,
  `failure_code`, `cancellation_reason`, `last_payment_error`, or
  `failure_reason` from the payload — so downstream UIs and alerts
  carry the actual reason instead of "see Stripe Dashboard".
- Inspector renders a prominent `✓ Payment succeeded` /
  `✗ Payment did not succeed: <reason>` / `⏳ Payment in flight: <reason>`
  callout on the detail page when the payload carries a payment
  outcome, plus a small inline `✓ paid` / `✗ <code>` / `⏳ in flight`
  annotation next to each row's event type in the table.
- Inspector now distinguishes "processed with handlers" from "processed
  without handlers" via separate badges (green `processed` vs gray
  `no-op`).
- `Support\StripeReconciler` — refetches Stripe objects and (optionally)
  re-runs handlers against fresh state. Two entry points: `fetchObject($id)`
  for app code with a stored Stripe id, and `reconcile(WebhookCall)` for
  stuck webhook rows. Uses the new `Contracts\StripeObjectFetcher`
  interface so users can swap the underlying transport.
- `Support\SdkStripeObjectFetcher` — default implementation routing
  by id prefix (`pi_`, `ch_`, `cs_`, `cus_`, `sub_`, `in_`, `evt_`).
- `Events\WebhookReconciled` — fired after a reconcile so apps can
  record audit trails.
- ServiceProvider auto-registers `\Stripe\StripeClient` from
  `services.stripe.secret` (via `bindIf` so existing bindings win).

### Changed
- `StripeWebhookHandler::$backoff` type relaxed from `array|int` to
  just `array`. Aligns with the SPEC's documented signature and removes
  a foot-gun where child classes declaring `public array $backoff = …`
  hit a fatal type-mismatch on instantiation. Single-attempt backoff is
  expressed as a single-element array (`[60]`).
- `RunStripeHandler::resolveBackoff()` simplified to remove the
  now-impossible `is_int` branch.

## [1.0.0-rc.1] — 2026-05-06

First release candidate of the free core. Free module of the wider
*Complete Stripe Toolkit for Laravel*.

### Added

#### Core pipeline (store-then-process)
- `StripeWebhookController` verifies Stripe signature, persists the call
  with `event_id` UNIQUE, dispatches `ProcessStripeWebhook` to a queue,
  and returns 200 in <100ms.
- Idempotency at the event level: receiving the same `event.id` twice
  returns 200 with `{"status":"duplicate"}` and does not re-dispatch.
- `ProcessStripeWebhook` resolves handlers (config map + attribute
  discovery) and dispatches one `RunStripeHandler` per handler so each
  retries independently.
- `RunStripeHandler` honours per-handler `$tries` and `$backoff`, marks
  per-run rows in `stripe_webhook_handler_runs`, fires
  `WebhookHandlerFailed` on each failed attempt, and `WebhookDeadLettered`
  + `WebhookCall::status = dead_letter` once attempts are exhausted.

#### Adapter snapshot ↔ thin events
- `EventResolver` detects format from `payload.object` (`event` vs
  `v2.core.event`) before signature verification.
- `SnapshotEventDTO` wraps `\Stripe\Event` natively; `relatedObject()`
  returns the typed Stripe object (`\Stripe\PaymentIntent`, etc.) at
  zero API cost.
- `ThinEventDTO` wraps `\Stripe\V2\Event` (or a typed subclass when the
  SDK exposes one via `\Stripe\Util\EventTypes::thinEventMapping`) and
  hydrates the related object lazily, caching the result behind a
  sentinel so a single call doesn't make two API requests.
- `TypeNormalizer::normalize()` strips `v1.`, `v2.core.` prefixes so a
  handler registered for `payment_intent.succeeded` also matches
  `v1.payment_intent.succeeded`.

#### Configuration & routing
- `StripeWebhook::route('stripe/webhook')` macro registers the controller.
- Multi-secret Connect: `StripeWebhook::route('stripe/webhook/{configKey}')`
  reads from `config('stripe-webhooks.webhook_secrets.{configKey}')`.
- `accountId()` on the DTO surfaces the connected account id from the
  payload (snapshot: `event.account`; thin: `notification.context`).

#### Routing API
- `#[StripeEvent('event.type')]` PHP 8 attribute on handler classes
  (repeatable for multiple types). `HandlerDiscovery` scans
  `discover_path` (default `app_path('Stripe/Handlers')`) and merges
  with the explicit `handlers` config map.

#### Persistence
- Two migrations: `stripe_webhook_calls` and `stripe_webhook_handler_runs`.
  Status enums on both, `last_error_category` on per-run rows, indexes
  on `(type, status)` and `(webhook_call_id, handler_class)`.

#### Errors
- Three typed exceptions: `InvalidSignatureException` (400),
  `UnrecognizedPayloadException` (400), `UnsupportedSdkVersionException`
  (500). All implement `Symfony\…\HttpExceptionInterface`.

#### Debug inspector (dev-mode only)
- Read-only Blade UI at `/stripe-webhooks-debug` listing recent calls
  with status counters, filters, and detail view with handler runs +
  pretty-printed payload.
- Embedded iframe form trigger that signs payloads server-side and
  POSTs them to the configured webhook route — useful for replicating
  Stripe's send-shape during development.
- Auto-disabled in `production` unless `STRIPE_WEBHOOKS_DEBUG=true`.

#### Testing helpers
- Test fixtures (`tests/Fixtures/Fixtures.php`) for snapshot and thin
  payloads, including a Connect variant.
- `tests/Support/SignedPayload.php` produces Stripe-compatible
  `Stripe-Signature` headers for use in feature tests.

#### Tooling
- 27 Pest tests covering signature verification, idempotency, handler
  dispatch, dead-letter, event resolver routing, DTO hydration,
  type normalization, and handler discovery.
- GitHub Actions CI matrix with three jobs: PHP 8.2 / Laravel 11 /
  Stripe SDK 15, PHP 8.3 / Laravel 12 / Stripe SDK 16, PHP 8.4 /
  Laravel 13 / Stripe SDK 17.
- `php artisan stripe-webhooks:install` publishes config and migrations.

### Compatibility
- PHP `^8.2`
- Laravel `^11.0 || ^12.0 || ^13.0`
- `stripe/stripe-php` `^15.0 || ^16.0 || ^17.0`
- Filament `^4.0` *(optional, for the upcoming Pro module)*

[Unreleased]: https://github.com/transistorized-cmd/stripe-toolkit-webhooks/compare/v1.0.0-rc.1...HEAD
[1.0.0-rc.1]: https://github.com/transistorized-cmd/stripe-toolkit-webhooks/releases/tag/v1.0.0-rc.1
