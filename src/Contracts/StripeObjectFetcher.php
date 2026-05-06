<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Contracts;

use Stripe\Exception\ApiErrorException;

/**
 * Fetches the current state of a Stripe object given its id (e.g.
 * `pi_…`, `ch_…`, `cs_…`). The kit ships an SDK-backed default
 * (`SdkStripeObjectFetcher`) wired in the service provider; users can
 * swap with a fake in tests by binding their own implementation.
 */
interface StripeObjectFetcher
{
    /**
     * Return the typed Stripe object for the given id. Implementations
     * route by id prefix.
     *
     * @throws \DomainException if the prefix is unknown
     * @throws ApiErrorException if the API call fails
     */
    public function fetch(string $stripeId): object;
}
