<?php

declare(strict_types=1);

use Stripe\V2\Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Adapters\EventResolver;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\SnapshotEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\ThinEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\InvalidSignatureException;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnrecognizedPayloadException;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Support\SignedPayload;

/*
 * The resolver detects format from `payload.object` BEFORE verifying the
 * signature, so a single endpoint can route both formats to the right
 * adapter. Verification still happens after — an attacker who picks the
 * wrong path just causes verification to fail.
 */

it('routes a snapshot payload to the snapshot adapter', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded();
    $body = SignedPayload::body($payload);
    $sig = SignedPayload::header($body, 'whsec_test_default');

    /** @var EventResolver $resolver */
    $resolver = app(EventResolver::class);

    $dto = $resolver->resolve($body, $sig, 'default');

    expect($dto)->toBeInstanceOf(SnapshotEventDTO::class)
        ->and($dto->normalizedType())->toBe('payment_intent.succeeded');
});

it('routes a thin payload to the thin adapter', function () {
    if (! class_exists(Event::class)) {
        $this->markTestSkipped('Thin events require stripe/stripe-php ^17.');
    }

    $payload = Fixtures::thinV1CustomerCreated();
    $body = SignedPayload::body($payload);
    $sig = SignedPayload::header($body, 'whsec_test_default');

    /** @var EventResolver $resolver */
    $resolver = app(EventResolver::class);

    $dto = $resolver->resolve($body, $sig, 'default');

    expect($dto)->toBeInstanceOf(ThinEventDTO::class)
        ->and($dto->type())->toBe('v1.customer.created')
        ->and($dto->normalizedType())->toBe('customer.created');
});

it('throws UnrecognizedPayloadException when object field is unknown', function () {
    $payload = Fixtures::unknownObject();
    $body = SignedPayload::body($payload);
    $sig = SignedPayload::header($body, 'whsec_test_default');

    /** @var EventResolver $resolver */
    $resolver = app(EventResolver::class);

    expect(fn () => $resolver->resolve($body, $sig, 'default'))
        ->toThrow(UnrecognizedPayloadException::class, 'Cannot detect webhook format');
});

it('throws UnrecognizedPayloadException when payload is malformed JSON', function () {
    /** @var EventResolver $resolver */
    $resolver = app(EventResolver::class);

    expect(fn () => $resolver->resolve('{not valid', 't=1,v1=abc', 'default'))
        ->toThrow(UnrecognizedPayloadException::class, 'not valid JSON');
});

it('throws InvalidSignatureException when the thin signature does not match', function () {
    if (! class_exists(Event::class)) {
        $this->markTestSkipped('Thin events require stripe/stripe-php ^17.');
    }

    $payload = Fixtures::thinV1CustomerCreated();
    $body = SignedPayload::body($payload);
    $sig = SignedPayload::header($body, 'whsec_wrong_secret');

    /** @var EventResolver $resolver */
    $resolver = app(EventResolver::class);

    expect(fn () => $resolver->resolve($body, $sig, 'default'))
        ->toThrow(InvalidSignatureException::class);
});
