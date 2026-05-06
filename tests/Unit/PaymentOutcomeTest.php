<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\PaymentOutcome;

it('reads charge.succeeded as success', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'charge.succeeded',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'status' => 'succeeded']],
    ]);

    expect($outcome->applies())->toBeTrue()
        ->and($outcome->isSuccess())->toBeTrue()
        ->and($outcome->message)->toBeNull();
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

it('reads payment_intent.succeeded from object.status', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['status' => 'succeeded']],
    ]);

    expect($outcome->isSuccess())->toBeTrue();
});

it('reads payment_intent.payment_failed and pulls last_payment_error', function () {
    $outcome = PaymentOutcome::fromPayload([
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => [
            'status' => 'requires_payment_method',
            'last_payment_error' => [
                'code' => 'card_declined',
                'message' => 'Your card has insufficient funds.',
            ],
        ]],
    ]);

    expect($outcome->isFailure())->toBeTrue()
        ->and($outcome->message)->toBe('Your card has insufficient funds.')
        ->and($outcome->code)->toBe('card_declined');
});

it('reads checkout.session.completed payment_status', function () {
    $paid = PaymentOutcome::fromPayload([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['payment_status' => 'paid']],
    ]);
    $unpaid = PaymentOutcome::fromPayload([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['payment_status' => 'unpaid']],
    ]);
    $free = PaymentOutcome::fromPayload([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['payment_status' => 'no_payment_required']],
    ]);

    expect($paid->isSuccess())->toBeTrue()
        ->and($unpaid->isFailure())->toBeTrue()
        ->and($free->isSuccess())->toBeTrue();
});

it('reads invoice status', function () {
    $paid = PaymentOutcome::fromPayload([
        'type' => 'invoice.paid',
        'data' => ['object' => ['status' => 'paid']],
    ]);
    $uncoll = PaymentOutcome::fromPayload([
        'type' => 'invoice.marked_uncollectible',
        'data' => ['object' => ['status' => 'uncollectible']],
    ]);

    expect($paid->isSuccess())->toBeTrue()
        ->and($uncoll->isFailure())->toBeTrue();
});

it('marks refunds and disputes as failure (negative outcome against a prior success)', function () {
    $refund = PaymentOutcome::fromPayload([
        'type' => 'charge.refunded',
        'data' => ['object' => ['object' => 'charge', 'paid' => true, 'amount_refunded' => 4200]],
    ]);
    $dispute = PaymentOutcome::fromPayload([
        'type' => 'charge.dispute.created',
        'data' => ['object' => ['object' => 'dispute']],
    ]);

    expect($refund->isFailure())->toBeTrue()
        ->and($dispute->isFailure())->toBeTrue();
});

it('returns none() for non-payment events', function (string $type) {
    $outcome = PaymentOutcome::fromPayload([
        'type' => $type,
        'data' => ['object' => ['id' => 'cus_1']],
    ]);

    expect($outcome->applies())->toBeFalse()
        ->and($outcome->paid)->toBeNull();
})->with([
    'customer.created',
    'customer.updated',
    'payment_method.attached',
    'product.created',
]);

it('returns none() for malformed payloads', function () {
    expect(PaymentOutcome::fromPayload([])->applies())->toBeFalse()
        ->and(PaymentOutcome::fromPayload(['type' => 'charge.succeeded'])->applies())->toBeFalse()
        ->and(PaymentOutcome::fromPayload(['type' => 'charge.succeeded', 'data' => ['object' => []]])->applies())->toBeFalse();
});
