<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

/**
 * Classifies Stripe event types by their business outcome semantic.
 *
 * Useful for UIs (the kit's debug inspector uses this to flag failure
 * events visually) and for handlers that want to react to "anything
 * negative" without enumerating every type. Independent of whether
 * the kit actually ran a handler — the kit's own status describes its
 * processing, this enum describes what Stripe is telling you happened.
 */
enum EventOutcome: string
{
    /** Successful business outcome — `*.succeeded`, `*.created`, `*.completed`, etc. */
    case Success = 'success';

    /** Negative business outcome — `*.failed`, `*.canceled`, `*.expired`, refunds, disputes. */
    case Failure = 'failure';

    /** Neutral — informational events like `*.updated`, `*.attached`, generic notifications. */
    case Neutral = 'neutral';

    public static function classify(string $eventType): self
    {
        $normalized = TypeNormalizer::normalize($eventType);

        if (preg_match('/(?:\.|_)(failed|canceled|expired|refunded|payment_failed|payment_action_required|past_due|uncollectible|deleted|disputed)$/i', $normalized) === 1) {
            return self::Failure;
        }

        if (str_contains($normalized, '.dispute.') || str_contains($normalized, 'charge.refund') || str_ends_with($normalized, 'review.opened')) {
            return self::Failure;
        }

        if (preg_match('/\.(succeeded|completed|paid|finalized|created|attached)$/i', $normalized) === 1) {
            return self::Success;
        }

        return self::Neutral;
    }
}
