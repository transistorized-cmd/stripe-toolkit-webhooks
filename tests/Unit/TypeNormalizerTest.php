<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\TypeNormalizer;

it('strips v1./v2./vN. prefixes from event types', function (string $input, string $expected) {
    expect(TypeNormalizer::normalize($input))->toBe($expected);
})->with([
    ['v1.customer.created', 'customer.created'],
    ['v2.core.event_destination.ping', 'core.event_destination.ping'],
    ['payment_intent.succeeded', 'payment_intent.succeeded'],
    ['v3.future.event', 'future.event'],
    ['no_prefix', 'no_prefix'],
]);
