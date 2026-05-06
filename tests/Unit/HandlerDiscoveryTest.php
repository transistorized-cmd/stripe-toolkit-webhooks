<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;

it('returns explicit handlers from config', function () {
    config()->set('stripe-webhooks.handlers', [
        'invoice.payment_failed' => ['App\\HandlerA', 'App\\HandlerB'],
    ]);
    config()->set('stripe-webhooks.discover_attributes', false);

    $discovery = new HandlerDiscovery;

    expect($discovery->for('invoice.payment_failed'))->toBe(['App\\HandlerA', 'App\\HandlerB']);
});

it('returns an empty array for unknown event types', function () {
    config()->set('stripe-webhooks.handlers', []);
    config()->set('stripe-webhooks.discover_attributes', false);

    expect((new HandlerDiscovery)->for('something.else'))->toBe([]);
});

it('deduplicates handlers when the same class appears twice', function () {
    config()->set('stripe-webhooks.handlers', [
        'charge.succeeded' => ['App\\Same', 'App\\Same'],
    ]);
    config()->set('stripe-webhooks.discover_attributes', false);

    expect((new HandlerDiscovery)->for('charge.succeeded'))->toBe(['App\\Same']);
});
