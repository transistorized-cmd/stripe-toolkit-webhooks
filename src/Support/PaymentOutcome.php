<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

/**
 * Authoritative read of the payment outcome carried by a Stripe event
 * payload — derived from `data.object`, NOT from the event type name.
 *
 * Four states (`PaymentOutcomeState`):
 *   - Succeeded:    money moved or doesn't need to (no_payment_required)
 *   - Failed:       money didn't move, or moved and was reversed
 *   - InFlight:     still in progress — async bank transfer, 3DS pending,
 *                   auth awaiting capture, partially funded, under review
 *   - Inapplicable: event is not about a payment (customer.created, etc.)
 *
 * Coverage:
 *   - charge.*                (succeeded/captured/failed/expired/pending/refunded)
 *   - charge.dispute.*        (created/closed/won/lost/funds_withdrawn/funds_reinstated)
 *   - charge.refund.*         (legacy refund nested-on-charge events)
 *   - payment_intent.*        (succeeded/payment_failed/canceled/processing/
 *                              requires_action/requires_capture/partially_funded)
 *   - checkout.session.*      (completed/async_payment_succeeded/_failed/expired)
 *   - invoice.*               (paid/payment_succeeded/payment_failed/
 *                              payment_action_required/marked_uncollectible/
 *                              voided/finalization_failed)
 *   - refund.*                (top-level Refund object — succeeded/failed/canceled/pending)
 *   - radar.early_fraud_warning.created
 *   - review.opened / review.closed
 *
 * Anything else returns `inapplicable()`.
 */
final class PaymentOutcome
{
    public function __construct(
        public readonly PaymentOutcomeState $state,
        public readonly ?string $message = null,
        public readonly ?string $code = null,
    ) {}

    public static function succeeded(?string $message = null): self
    {
        return new self(PaymentOutcomeState::Succeeded, $message);
    }

    public static function failed(?string $message = null, ?string $code = null): self
    {
        return new self(PaymentOutcomeState::Failed, $message, $code);
    }

    public static function inFlight(?string $message = null): self
    {
        return new self(PaymentOutcomeState::InFlight, $message);
    }

    public static function inapplicable(): self
    {
        return new self(PaymentOutcomeState::Inapplicable);
    }

    /**
     * Read the payment outcome from a webhook event payload.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $type = (string) ($payload['type'] ?? '');
        /** @var array<string,mixed> $object */
        $object = $payload['data']['object'] ?? [];
        if (! is_array($object) || $object === []) {
            return self::inapplicable();
        }

        // Strip `v1.` from thin-event types so the same logic applies
        // to both snapshot and thin variants.
        $type = TypeNormalizer::normalize($type);

        if ($outcome = self::classifyDispute($type, $object)) {
            return $outcome;
        }

        if ($outcome = self::classifyRefund($type, $object)) {
            return $outcome;
        }

        if (str_starts_with($type, 'charge.')) {
            return self::classifyCharge($object);
        }

        if (str_starts_with($type, 'payment_intent.')) {
            return self::classifyPaymentIntent($object);
        }

        if (str_starts_with($type, 'checkout.session.')) {
            return self::classifyCheckoutSession($type, $object);
        }

        if (str_starts_with($type, 'invoice.')) {
            return self::classifyInvoice($type, $object);
        }

        if (str_starts_with($type, 'radar.early_fraud_warning.')) {
            return self::failed(
                self::stringOrNull($object['fraud_type'] ?? null) ?? 'Early fraud warning issued.',
            );
        }

        if (str_starts_with($type, 'review.')) {
            return self::classifyReview($type, $object);
        }

        return self::inapplicable();
    }

    public function applies(): bool
    {
        return $this->state !== PaymentOutcomeState::Inapplicable;
    }

    public function isSuccess(): bool
    {
        return $this->state === PaymentOutcomeState::Succeeded;
    }

    public function isFailure(): bool
    {
        return $this->state === PaymentOutcomeState::Failed;
    }

    public function isInFlight(): bool
    {
        return $this->state === PaymentOutcomeState::InFlight;
    }

    /** Three-state convenience: true / false / null (in-flight or inapplicable). */
    public function paid(): ?bool
    {
        return match ($this->state) {
            PaymentOutcomeState::Succeeded => true,
            PaymentOutcomeState::Failed => false,
            PaymentOutcomeState::InFlight, PaymentOutcomeState::Inapplicable => null,
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-family classifiers
    // ──────────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $object */
    private static function classifyDispute(string $type, array $object): ?self
    {
        if (! str_contains($type, '.dispute.')) {
            return null;
        }

        if ($type === 'charge.dispute.funds_withdrawn') {
            return self::failed('Dispute opened — funds withdrawn by Stripe.');
        }

        if ($type === 'charge.dispute.funds_reinstated') {
            return self::succeeded('Dispute resolved in your favor — funds reinstated.');
        }

        if ($type === 'charge.dispute.closed') {
            $status = self::stringOrNull($object['status'] ?? null);

            return match ($status) {
                'won' => self::succeeded('Dispute closed in your favor.'),
                'lost' => self::failed('Dispute lost — funds not recovered.'),
                'warning_closed' => self::succeeded('Dispute warning closed without escalation.'),
                default => self::failed('Dispute closed with status '.($status ?? 'unknown').'.'),
            };
        }

        $status = self::stringOrNull($object['status'] ?? null);
        if (in_array($status, ['warning_needs_response', 'warning_under_review', 'needs_response', 'under_review'], true)) {
            return self::inFlight('Dispute open — '.$status.'.');
        }

        return self::failed('Dispute event — see payload for details.');
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyRefund(string $type, array $object): ?self
    {
        if ($type === 'charge.refunded' || str_starts_with($type, 'charge.refund.')) {
            return self::failed('Refund issued — funds returned to customer.');
        }

        if (str_starts_with($type, 'refund.')) {
            $status = self::stringOrNull($object['status'] ?? null);

            return match ($status) {
                'succeeded' => self::failed('Refund succeeded — funds returned to customer.'),
                'failed' => self::failed(
                    self::stringOrNull($object['failure_reason'] ?? null) ?? 'Refund attempt failed.',
                    self::stringOrNull($object['failure_reason'] ?? null),
                ),
                'pending' => self::inFlight('Refund being processed.'),
                'canceled' => self::failed('Refund canceled before completion.'),
                default => self::inapplicable(),
            };
        }

        return null;
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyCharge(array $object): self
    {
        $paid = $object['paid'] ?? null;
        $status = self::stringOrNull($object['status'] ?? null);
        $captured = $object['captured'] ?? null;

        // Async charges: pending bank confirmation.
        if ($status === 'pending') {
            return self::inFlight('Charge pending — async payment method awaiting confirmation.');
        }

        // Authorization placed but not yet captured (auth-then-capture flow).
        if ($status === 'succeeded' && $captured === false) {
            return self::inFlight('Authorization placed, awaiting capture.');
        }

        if (! is_bool($paid)) {
            return self::inapplicable();
        }

        if ($paid) {
            return self::succeeded();
        }

        // paid: false — usually a decline; surface what Stripe said.
        return self::failed(
            self::stringOrNull($object['failure_message'] ?? null),
            self::stringOrNull($object['failure_code'] ?? null),
        );
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyPaymentIntent(array $object): self
    {
        $status = self::stringOrNull($object['status'] ?? null);

        return match ($status) {
            'succeeded' => self::succeeded(),
            'requires_payment_method' => self::failed(
                self::stringOrNull(($object['last_payment_error'] ?? [])['message'] ?? null),
                self::stringOrNull(($object['last_payment_error'] ?? [])['code'] ?? null),
            ),
            'canceled' => self::failed(
                self::stringOrNull($object['cancellation_reason'] ?? null) ?? 'PaymentIntent canceled.',
            ),
            'processing' => self::inFlight('Async payment processing — awaiting bank confirmation.'),
            'requires_action' => self::inFlight('Awaiting customer action — 3DS or redirect.'),
            'requires_confirmation' => self::inFlight('Awaiting confirmation.'),
            'requires_capture' => self::inFlight('Authorization placed, awaiting capture.'),
            default => self::inapplicable(),
        };
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyCheckoutSession(string $type, array $object): self
    {
        // Specific event types whose name is more authoritative than
        // payment_status (which lags for async failures).
        if ($type === 'checkout.session.async_payment_failed') {
            return self::failed('Async payment failed (e.g. SEPA bounce, ACH return).');
        }

        if ($type === 'checkout.session.expired') {
            return self::failed('Checkout session expired before payment.');
        }

        $status = self::stringOrNull($object['payment_status'] ?? null);

        return match ($status) {
            'paid', 'no_payment_required' => self::succeeded(),
            'unpaid' => self::inFlight(
                'Checkout completed; payment_status=unpaid (typical for async methods awaiting bank confirmation).',
            ),
            default => self::inapplicable(),
        };
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyInvoice(string $type, array $object): self
    {
        // Some invoice events keep status='open' on the object until the
        // next attempt — trust the event type for those.
        if ($type === 'invoice.payment_failed') {
            $error = $object['last_finalization_error'] ?? $object['last_payment_error'] ?? [];

            return self::failed(
                self::stringOrNull($error['message'] ?? null) ?? 'Invoice payment failed; will retry.',
                self::stringOrNull($error['code'] ?? null),
            );
        }

        if ($type === 'invoice.payment_action_required') {
            return self::inFlight('Customer authentication required to complete invoice payment.');
        }

        if ($type === 'invoice.finalization_failed') {
            $error = $object['last_finalization_error'] ?? [];

            return self::failed(
                self::stringOrNull($error['message'] ?? null) ?? 'Invoice failed to finalize.',
                self::stringOrNull($error['code'] ?? null),
            );
        }

        $status = self::stringOrNull($object['status'] ?? null);

        return match ($status) {
            'paid' => self::succeeded(),
            'uncollectible' => self::failed('Invoice marked uncollectible (write-off).'),
            'void' => self::failed('Invoice voided.'),
            'open' => self::inFlight('Invoice open; awaiting payment or retry.'),
            'draft' => self::inapplicable(),
            default => self::inapplicable(),
        };
    }

    /** @param  array<string,mixed>  $object */
    private static function classifyReview(string $type, array $object): self
    {
        if ($type === 'review.opened') {
            return self::inFlight('Charge under fraud review.');
        }

        if ($type === 'review.closed') {
            $reason = self::stringOrNull($object['closed_reason'] ?? null);

            return match ($reason) {
                'approved' => self::succeeded('Review closed — approved.'),
                'refunded', 'refunded_as_fraud', 'disputed' => self::failed(
                    'Review closed — '.$reason.'.',
                ),
                default => self::failed('Review closed.'),
            };
        }

        return self::inapplicable();
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
