<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

/**
 * The four discrete states a payment can be in from the kit's
 * perspective:
 *
 *   - Succeeded: money moved (or doesn't need to). Order can be fulfilled.
 *   - Failed: money did not move and won't (this attempt). Or a previously
 *     successful payment was reversed (refund / lost dispute).
 *   - InFlight: still in progress — async bank transfer, awaiting 3DS,
 *     auth pending capture, etc. Definitive outcome unknown.
 *   - Inapplicable: the event isn't about a payment outcome at all
 *     (customer.created, payment_method.attached, …).
 */
enum PaymentOutcomeState: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case InFlight = 'in_flight';
    case Inapplicable = 'inapplicable';
}
