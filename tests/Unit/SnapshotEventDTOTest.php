<?php

declare(strict_types=1);

use Stripe\PaymentIntent;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\EventSource;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\SnapshotEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;

it('hydrates snapshot event payload into native Stripe types', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded();
    $dto = SnapshotEventDTO::fromArray($payload);

    expect($dto->id())->toBe('evt_test_3PZ8aBC2eZvKYlo2xC1aBcD4')
        ->and($dto->type())->toBe('payment_intent.succeeded')
        ->and($dto->normalizedType())->toBe('payment_intent.succeeded')
        ->and($dto->apiVersion())->toBe('2025-06-30.basil')
        ->and($dto->livemode())->toBeFalse()
        ->and($dto->accountId())->toBeNull()
        ->and($dto->sourceFormat())->toBe(EventSource::Snapshot)
        ->and($dto->createdAt()->getTimestamp())->toBe(1730000000);

    $object = $dto->relatedObject();
    expect($object)->toBeInstanceOf(PaymentIntent::class)
        ->and($object->id)->toBe('pi_3PZ8aBC2eZvKYlo20HsXY3aB')
        ->and($object->amount)->toBe(4200)
        ->and($object->currency)->toBe('eur')
        ->and($object->status)->toBe('succeeded');
});

it('extracts the connected account id when present', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded(accountId: 'acct_1PZ8aBCConnectedAcc');
    $dto = SnapshotEventDTO::fromArray($payload);

    expect($dto->accountId())->toBe('acct_1PZ8aBCConnectedAcc');
});

it('preserves the raw payload string', function () {
    $payload = Fixtures::snapshotPaymentIntentSucceeded();
    $dto = SnapshotEventDTO::fromArray($payload);

    expect(json_decode($dto->rawPayload(), true))->toBe($payload);
});
