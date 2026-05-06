<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\PaymentOutcome;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\PaymentOutcomeState;

// ──────────────────────────────────────────────────────────────────
// Charge
// ──────────────────────────────────────────────────────────────────

it('reads charge.succeeded as success', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.succeeded',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'status' => 'succeeded', 'captured' => true]],
    ]);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->state)->toBe(PaymentOutcomeState::Succeeded);
});

it('reads charge.failed and surfaces failure_message + failure_code', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.failed',
        'data' => ['object' => [
            'object' => 'charge',
            'paid' => false,
            'failure_code' => 'card_declined',
            'failure_message' => 'Your card was declined.',
        ]],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->message)->toBe('Your card was declined.')
        ->and($outcome->code)->toBe('card_declined');
});

it('treats charge with status=pending as in-flight (async)', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.pending',
        'data' => ['object' => ['object' => 'charge', 'paid' => false, 'status' => 'pending']],
    ]);

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->message)->toContain('async');
});

it('treats charge with status=succeeded captured=false as in-flight (auth-only)', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.captured',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'status' => 'succeeded', 'captured' => false]],
    ]);

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->message)->toContain('capture');
});

it('treats fully-captured charge as success', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.captured',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'status' => 'succeeded', 'captured' => true]],
    ]);

    expect($outcome->isSuccess())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────
// PaymentIntent
// ──────────────────────────────────────────────────────────────────

it('reads payment_intent.succeeded', function () {
    expect(PaymentOutcome::fromPayload([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['status' => 'succeeded']],
    ])->isSuccess())->toBeTrue();
});

it('reads payment_intent.payment_failed with last_payment_error', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => [
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card has insufficient funds.'],
        ]],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->message)->toBe('Your card has insufficient funds.')
        ->and($outcome->code)->toBe('card_declined');
});

it('reads payment_intent.canceled with cancellation_reason', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'payment_intent.canceled',
        'data' => ['object' => ['status' => 'canceled', 'cancellation_reason' => 'fraudulent']],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->message)->toBe('fraudulent');
});

it('classifies in-flight PaymentIntent statuses', function (string $status, string $needle) {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'payment_intent.processing',
        'data' => ['object' => ['status' => $status]],
    ]);

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->message)->toContain($needle);
})->with([
    ['processing', 'Async'],
    ['requires_action', '3DS'],
    ['requires_confirmation', 'confirmation'],
    ['requires_capture', 'capture'],
]);

// ──────────────────────────────────────────────────────────────────
// Checkout Session
// ──────────────────────────────────────────────────────────────────

it('classifies checkout.session by payment_status and event type', function (array $payload, string $expected) {
    $outcome = PaymentOutcome::fromPayload($payload);
    expect($outcome->state->value)->toBe($expected);
})->with([
    'paid' => [['type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'paid']]], 'succeeded'],
    'no_payment_required' => [['type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'no_payment_required']]], 'succeeded'],
    'unpaid (in-flight)' => [['type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'unpaid']]], 'in_flight'],
    'async_payment_succeeded' => [['type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => ['payment_status' => 'paid']]], 'succeeded'],
    'async_payment_failed' => [['type' => 'checkout.session.async_payment_failed', 'data' => ['object' => ['payment_status' => 'unpaid']]], 'failed'],
    'expired' => [['type' => 'checkout.session.expired', 'data' => ['object' => ['payment_status' => 'unpaid']]], 'failed'],
]);

// ──────────────────────────────────────────────────────────────────
// Invoice
// ──────────────────────────────────────────────────────────────────

it('classifies invoice events', function (array $payload, string $expected) {
    expect(PaymentOutcome::fromPayload($payload)->state->value)->toBe($expected);
})->with([
    'paid' => [['type' => 'invoice.paid', 'data' => ['object' => ['status' => 'paid']]], 'succeeded'],
    'payment_succeeded' => [['type' => 'invoice.payment_succeeded', 'data' => ['object' => ['status' => 'paid']]], 'succeeded'],
    'payment_failed (status=open)' => [['type' => 'invoice.payment_failed', 'data' => ['object' => ['status' => 'open', 'attempt_count' => 1]]], 'failed'],
    'payment_action_required' => [['type' => 'invoice.payment_action_required', 'data' => ['object' => ['status' => 'open']]], 'in_flight'],
    'finalization_failed' => [['type' => 'invoice.finalization_failed', 'data' => ['object' => ['status' => 'draft', 'last_finalization_error' => ['message' => 'Tax error']]]], 'failed'],
    'marked_uncollectible' => [['type' => 'invoice.marked_uncollectible', 'data' => ['object' => ['status' => 'uncollectible']]], 'failed'],
    'voided' => [['type' => 'invoice.voided', 'data' => ['object' => ['status' => 'void']]], 'failed'],
    'open (no specific event)' => [['type' => 'invoice.updated', 'data' => ['object' => ['status' => 'open']]], 'in_flight'],
    'draft' => [['type' => 'invoice.created', 'data' => ['object' => ['status' => 'draft']]], 'inapplicable'],
]);

// ──────────────────────────────────────────────────────────────────
// Disputes
// ──────────────────────────────────────────────────────────────────

it('classifies dispute events with nuance', function (array $payload, string $expected) {
    expect(PaymentOutcome::fromPayload($payload)->state->value)->toBe($expected);
})->with([
    'created (under review)' => [['type' => 'charge.dispute.created', 'data' => ['object' => ['status' => 'needs_response']]], 'in_flight'],
    'funds_withdrawn' => [['type' => 'charge.dispute.funds_withdrawn', 'data' => ['object' => ['status' => 'needs_response']]], 'failed'],
    'funds_reinstated (won money back)' => [['type' => 'charge.dispute.funds_reinstated', 'data' => ['object' => ['status' => 'won']]], 'succeeded'],
    'closed: won' => [['type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'won']]], 'succeeded'],
    'closed: lost' => [['type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'lost']]], 'failed'],
    'closed: warning_closed' => [['type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'warning_closed']]], 'succeeded'],
    'updated under review' => [['type' => 'charge.dispute.updated', 'data' => ['object' => ['status' => 'under_review']]], 'in_flight'],
]);

// ──────────────────────────────────────────────────────────────────
// Refunds
// ──────────────────────────────────────────────────────────────────

it('classifies legacy charge-nested refund events as failure', function () {
    $r1 = PaymentOutcome::fromPayload([
        'type' => 'charge.refunded',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'amount_refunded' => 4200]],
    ]);
    $r2 = PaymentOutcome::fromPayload([
        'type' => 'charge.refund.updated',
        'data' => ['object' => ['object' => 'refund', 'status' => 'succeeded']],
    ]);

    expect($r1->isFailure())->toBeTrue()
        ->and($r2->isFailure())->toBeTrue();
});

it('classifies top-level Refund (v2) events by status', function (string $status, string $expected) {
    expect(PaymentOutcome::fromPayload([
        'type' => 'refund.updated',
        'data' => ['object' => ['object' => 'refund', 'status' => $status, 'failure_reason' => 'lost_or_stolen_card']],
    ])->state->value)->toBe($expected);
})->with([
    ['succeeded', 'failed'],
    ['failed', 'failed'],
    ['pending', 'in_flight'],
    ['canceled', 'failed'],
]);

// ──────────────────────────────────────────────────────────────────
// Radar / Review
// ──────────────────────────────────────────────────────────────────

it('flags radar.early_fraud_warning.created as failure with fraud_type', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'radar.early_fraud_warning.created',
        'data' => ['object' => ['fraud_type' => 'made_with_stolen_card']],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->message)->toBe('made_with_stolen_card');
});

it('classifies review events', function (array $payload, string $expected) {
    expect(PaymentOutcome::fromPayload($payload)->state->value)->toBe($expected);
})->with([
    'opened' => [['type' => 'review.opened', 'data' => ['object' => ['reason' => 'rule']]], 'in_flight'],
    'closed approved' => [['type' => 'review.closed', 'data' => ['object' => ['closed_reason' => 'approved']]], 'succeeded'],
    'closed refunded' => [['type' => 'review.closed', 'data' => ['object' => ['closed_reason' => 'refunded']]], 'failed'],
    'closed refunded_as_fraud' => [['type' => 'review.closed', 'data' => ['object' => ['closed_reason' => 'refunded_as_fraud']]], 'failed'],
    'closed disputed' => [['type' => 'review.closed', 'data' => ['object' => ['closed_reason' => 'disputed']]], 'failed'],
]);

// ──────────────────────────────────────────────────────────────────
// Inapplicable
// ──────────────────────────────────────────────────────────────────

it('returns inapplicable() for non-payment events', function (string $type) {
    $outcome = PaymentOutcome::fromPayload([
        'type' => $type,
        'data' => ['object' => ['id' => 'cus_1']],
    ]);

    expect($outcome->applies())->toBeFalse()
        ->and($outcome->paid())->toBeNull();
})->with([
    'customer.created',
    'customer.updated',
    'payment_method.attached',
    'product.created',
    'price.created',
    'plan.created',
    'tax_rate.created',
]);

it('returns inapplicable() for malformed payloads', function () {
    expect(PaymentOutcome::fromPayload([])->applies())->toBeFalse()
        ->and(PaymentOutcome::fromPayload(['type' => 'charge.succeeded'])->applies())->toBeFalse()
        ->and(PaymentOutcome::fromPayload(['type' => 'charge.succeeded', 'data' => ['object' => []]])->applies())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────
// Thin event prefix normalization
// ──────────────────────────────────────────────────────────────────

it('handles thin-event v1.* prefix on the type', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'v1.charge.failed',
        'data' => ['object' => ['object' => 'charge', 'paid' => false, 'failure_code' => 'card_declined']],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->code)->toBe('card_declined');
});

// ──────────────────────────────────────────────────────────────────
// Three-state convenience
// ──────────────────────────────────────────────────────────────────

it('paid() returns three-state convenience', function () {
    expect(PaymentOutcome::succeeded()->paid())->toBeTrue()
        ->and(PaymentOutcome::failed()->paid())->toBeFalse()
        ->and(PaymentOutcome::inFlight()->paid())->toBeNull()
        ->and(PaymentOutcome::inapplicable()->paid())->toBeNull();
});
