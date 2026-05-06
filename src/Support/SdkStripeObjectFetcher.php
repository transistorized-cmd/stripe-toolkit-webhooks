<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

use DomainException;
use Stripe\StripeClient;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\StripeObjectFetcher;

/**
 * Default StripeObjectFetcher implementation. Maps the id prefix to the
 * matching `\Stripe\Service\…` retrieve call on the SDK client.
 *
 * Coverage of common types in v1.0; extend in your app by binding your
 * own StripeObjectFetcher to the container if you need others (e.g.
 * `pm_`, `pr_`, `seti_`, …).
 */
class SdkStripeObjectFetcher implements StripeObjectFetcher
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function fetch(string $stripeId): object
    {
        $prefix = explode('_', $stripeId, 2)[0] ?? '';

        return match ($prefix) {
            'pi' => $this->stripe->paymentIntents->retrieve($stripeId),
            'ch' => $this->stripe->charges->retrieve($stripeId),
            'cs' => $this->stripe->checkout->sessions->retrieve($stripeId),
            'cus' => $this->stripe->customers->retrieve($stripeId),
            'sub' => $this->stripe->subscriptions->retrieve($stripeId),
            'in' => $this->stripe->invoices->retrieve($stripeId),
            'evt' => $this->stripe->events->retrieve($stripeId),
            default => throw new DomainException(
                "SdkStripeObjectFetcher has no built-in mapping for id prefix [{$prefix}_]. "
                .'Bind a custom Contracts\\StripeObjectFetcher implementation if you need it.'
            ),
        };
    }
}
