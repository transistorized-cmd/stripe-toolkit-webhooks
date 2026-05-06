# Changelog

All notable changes to `transistorized-cmd/stripe-toolkit-webhooks` will be
documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com)
and [Semantic Versioning](https://semver.org).

## [Unreleased]

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
