<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

/**
 * The payment outcome carried by an event payload.
 *
 * Reads the authoritative state from `data.object` (not from the event
 * type name) so it tells the truth even when types and outcomes don't
 * line up — e.g. a `charge.failed` payload with `paid: false`, or an
 * `invoice.payment_failed` with `last_payment_error`.
 *
 * `paid` is null for non-payment events (customer.created, etc.); use
 * `applies()` to decide whether to render the outcome at all.
 */
final class PaymentOutcome
{
    private function __construct(
        public readonly ?bool $paid,
        public readonly ?string $message,
        public readonly ?string $code,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function success(): self
    {
        return new self(true, null, null);
    }

    public static function failure(?string $message = null, ?string $code = null): self
    {
        return new self(false, $message, $code);
    }

    /**
     * Read the payment outcome from a Stripe webhook payload.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $type = (string) ($payload['type'] ?? '');
        /** @var array<string,mixed> $object */
        $object = $payload['data']['object'] ?? [];
        if (! is_array($object) || $object === []) {
            return self::none();
        }

        // Refunds and disputes are inherently negative outcomes against
        // a previously-successful charge — match BEFORE the generic
        // `charge.*` paid-flag rule, because the original `paid` is still
        // true on a refunded charge.
        if (str_starts_with($type, 'charge.refund.')
            || $type === 'charge.refunded'
            || str_contains($type, '.dispute.')) {
            return self::failure('Refund or dispute event — see payload for details.');
        }

        // charge.* — `paid` is the canonical signal, plus failure_message
        // and failure_code when the bank declined.
        if (str_starts_with($type, 'charge.')) {
            $paid = $object['paid'] ?? null;
            if (! is_bool($paid)) {
                return self::none();
            }
            if ($paid) {
                return self::success();
            }

            return self::failure(
                self::stringOrNull($object['failure_message'] ?? null),
                self::stringOrNull($object['failure_code'] ?? null),
            );
        }

        // payment_intent.* — `status` distinguishes succeeded from
        // requires_payment_method/canceled. Failure details live under
        // `last_payment_error`.
        if (str_starts_with($type, 'payment_intent.')) {
            $status = self::stringOrNull($object['status'] ?? null);

            return match ($status) {
                'succeeded' => self::success(),
                'requires_payment_method', 'canceled' => self::failure(
                    self::stringOrNull(($object['last_payment_error'] ?? [])['message'] ?? null),
                    self::stringOrNull(($object['last_payment_error'] ?? [])['code'] ?? null),
                ),
                default => self::none(),
            };
        }

        // checkout.session.* — `payment_status` distinguishes paid /
        // unpaid / no_payment_required.
        if (str_starts_with($type, 'checkout.session.')) {
            $status = self::stringOrNull($object['payment_status'] ?? null);

            return match ($status) {
                'paid', 'no_payment_required' => self::success(),
                'unpaid' => self::failure('Checkout completed without a successful payment.'),
                default => self::none(),
            };
        }

        // invoice.* — `status` runs paid → uncollectible → void.
        if (str_starts_with($type, 'invoice.')) {
            $status = self::stringOrNull($object['status'] ?? null);

            return match ($status) {
                'paid' => self::success(),
                'uncollectible', 'void' => self::failure(
                    'Invoice marked as '.$status.'.',
                ),
                default => self::none(),
            };
        }

        return self::none();
    }

    /** True when the payload carries a meaningful payment outcome. */
    public function applies(): bool
    {
        return $this->paid !== null;
    }

    public function isSuccess(): bool
    {
        return $this->paid === true;
    }

    public function isFailure(): bool
    {
        return $this->paid === false;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
