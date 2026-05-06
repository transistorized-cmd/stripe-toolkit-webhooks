---
layout: home

hero:
  name: "Stripe Toolkit"
  text: "Webhooks module"
  tagline: Bulletproof Stripe webhook handling for Laravel — store-then-process, idempotent, queue-backed, snapshot + thin events under one DTO.
  actions:
    - theme: brand
      text: Install
      link: /installation
    - theme: alt
      text: GitHub
      link: https://github.com/transistorized-cmd/stripe-toolkit-webhooks

features:
  - title: Idempotent by default
    details: Every webhook is keyed by Stripe's event_id. Re-deliveries return 200 without re-running your handlers.
  - title: Snapshot + thin events, one API
    details: Your handlers receive the same DTO regardless of source. Type normalization routes payment_intent.succeeded and v1.payment_intent.succeeded to the same handler.
  - title: Per-handler retries
    details: Each handler retries independently with its own backoff. One bad handler doesn't poison the rest.
  - title: Connect-aware
    details: Multi-secret routing for separate endpoints, plus accountId() in the DTO when events come from connected accounts.
  - title: Read-only debug inspector
    details: A dev-mode UI lists incoming events, handler runs, and stack traces. Includes a form trigger to send signed test events to your own endpoint.
  - title: First module of a toolkit
    details: This is the webhooks module of The Complete Stripe Toolkit for Laravel. More modules ship as the toolkit expands.
---
