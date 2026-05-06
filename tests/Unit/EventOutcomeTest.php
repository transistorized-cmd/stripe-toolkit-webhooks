<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\EventOutcome;

it('classifies failure-shape event types', function (string $type) {
    expect(EventOutcome::classify($type))->toBe(EventOutcome::Failure);
})->with([
    'charge.failed',
    'payment_intent.payment_failed',
    'payment_intent.canceled',
    'invoice.payment_failed',
    'invoice.marked_uncollectible',
    'checkout.session.expired',
    'charge.refunded',
    'charge.dispute.created',
    'charge.dispute.funds_withdrawn',
    'customer.subscription.deleted',
    'subscription_schedule.canceled',
    'v1.charge.failed',
    'v1.payment_intent.payment_failed',
]);

it('classifies success-shape event types', function (string $type) {
    expect(EventOutcome::classify($type))->toBe(EventOutcome::Success);
})->with([
    'payment_intent.succeeded',
    'charge.succeeded',
    'checkout.session.completed',
    'invoice.paid',
    'invoice.finalized',
    'payment_method.attached',
    'customer.created',
    'v1.payment_intent.succeeded',
]);

it('falls back to neutral for informational types', function (string $type) {
    expect(EventOutcome::classify($type))->toBe(EventOutcome::Neutral);
})->with([
    'payment_intent.updated',
    'customer.updated',
    'charge.updated',
    'something.entirely.unknown',
]);
