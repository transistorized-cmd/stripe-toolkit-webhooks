<?php

declare(strict_types=1);

use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers\CustomerCreatedHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers\MultiAttributeHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures\Handlers\RecorderHandler;

beforeEach(function () {
    config()->set('stripe-webhooks.handlers', []);
    config()->set('stripe-webhooks.discover_attributes', true);
    config()->set('stripe-webhooks.discover_path', __DIR__.'/../Fixtures/Handlers');

    // The discovery cache is per-instance; ensure each test sees a fresh map.
    app()->forgetInstance(HandlerDiscovery::class);
});

it('finds handlers via #[StripeEvent] attribute scan from disk', function () {
    /** @var HandlerDiscovery $discovery */
    $discovery = app(HandlerDiscovery::class);

    expect($discovery->for('payment_intent.succeeded'))->toBe([RecorderHandler::class]);
});

it('handles repeated #[StripeEvent] attributes', function () {
    /** @var HandlerDiscovery $discovery */
    $discovery = app(HandlerDiscovery::class);

    expect($discovery->for('invoice.payment_failed'))->toBe([MultiAttributeHandler::class])
        ->and($discovery->for('invoice.payment_action_required'))->toBe([MultiAttributeHandler::class]);
});

it('skips abstract handler classes', function () {
    /** @var HandlerDiscovery $discovery */
    $discovery = app(HandlerDiscovery::class);

    expect($discovery->for('should.not.be.discovered'))->toBe([]);
});

it('matches a snapshot-style handler against the thin event variant via normalizedType', function () {
    /** @var HandlerDiscovery $discovery */
    $discovery = app(HandlerDiscovery::class);

    expect($discovery->for('customer.created'))->toBe([CustomerCreatedHandler::class])
        ->and($discovery->for('v1.customer.created'))->toBe([CustomerCreatedHandler::class]);
});

it('merges config-map handlers with attribute-discovered ones', function () {
    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => ['App\\OtherHandler'],
    ]);

    /** @var HandlerDiscovery $discovery */
    $discovery = app(HandlerDiscovery::class);

    expect($discovery->for('payment_intent.succeeded'))
        ->toContain(RecorderHandler::class)
        ->toContain('App\\OtherHandler');
});
