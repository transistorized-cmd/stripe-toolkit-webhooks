<?php

declare(strict_types=1);

use Stripe\V2\Event as V2Event;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\EventSource;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\ThinEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Fixtures;

beforeEach(function () {
    if (! class_exists(V2Event::class)) {
        $this->markTestSkipped('Thin events require stripe/stripe-php ^17 (no \Stripe\V2\Event in this matrix slot).');
    }
});

it('hydrates thin event into a V2 event wrapper', function () {
    $payload = Fixtures::thinV1CustomerCreated();
    $dto = ThinEventDTO::fromArray($payload);

    expect($dto->id())->toBe('evt_test_65R9Ijk8dKEYZcXeRWn16R9A7j1FSQ3w3TGDPLLGSM4CW0')
        ->and($dto->type())->toBe('v1.customer.created')
        ->and($dto->normalizedType())->toBe('customer.created')
        ->and($dto->apiVersion())->toBeNull()
        ->and($dto->sourceFormat())->toBe(EventSource::Thin)
        ->and($dto->livemode())->toBeFalse()
        ->and($dto->accountId())->toBeNull()
        ->and($dto->v2Event())->toBeInstanceOf(V2Event::class);
});

it('returns the related object stub without an API call when no fetch method is mapped', function () {
    $payload = Fixtures::thinV1CustomerCreated();
    $dto = ThinEventDTO::fromArray($payload);

    // v1.customer.created has no generated subclass in the SDK as of v17.6,
    // so we wrap the base \Stripe\V2\Event and return the related_object
    // stub Stripe sent with the payload (no API call required).
    $related = $dto->relatedObject();

    expect($related)->not()->toBeNull()
        ->and($related->id)->toBe('cus_QXYZabc123')
        ->and($related->type)->toBe('customer');
});
