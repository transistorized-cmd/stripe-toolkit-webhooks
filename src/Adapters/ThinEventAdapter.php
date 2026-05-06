<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Adapters;

use Stripe\Exception\SignatureVerificationException;
use Stripe\Util\EventTypes;
use Stripe\V2\Event as V2Event;
use Stripe\WebhookSignature;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\ThinEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\InvalidSignatureException;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnrecognizedPayloadException;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnsupportedSdkVersionException;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\SignatureVerifier;

class ThinEventAdapter
{
    public function __construct(
        private readonly SignatureVerifier $signatureVerifier,
    ) {}

    /**
     * True iff the installed SDK ships the V2 event classes needed to
     * construct a thin event DTO.
     */
    public static function isSupported(): bool
    {
        return class_exists(V2Event::class);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function looksLikeThinEvent(array $payload): bool
    {
        return ($payload['object'] ?? null) === 'v2.core.event';
    }

    public function resolve(string $rawPayload, ?string $signatureHeader, string $configKey = 'default'): ThinEventDTO
    {
        if (! self::isSupported()) {
            throw UnsupportedSdkVersionException::thinEventsNeedV17();
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw InvalidSignatureException::missingHeader();
        }

        $secret = $this->signatureVerifier->resolveSecret($configKey);
        $tolerance = (int) config('stripe-webhooks.tolerance', 300);

        try {
            WebhookSignature::verifyHeader($rawPayload, $signatureHeader, $secret, $tolerance);
        } catch (SignatureVerificationException $e) {
            throw InvalidSignatureException::verificationFailed($e->getMessage());
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw UnrecognizedPayloadException::notJson($e->getMessage());
        }

        $type = (string) ($payload['type'] ?? '');
        $class = $this->resolveEventClass($type);
        $event = $class::constructFrom($payload);

        return new ThinEventDTO($event, $rawPayload);
    }

    /** @return class-string */
    private function resolveEventClass(string $type): string
    {
        if (class_exists(EventTypes::class) && isset(EventTypes::thinEventMapping[$type])) {
            return EventTypes::thinEventMapping[$type];
        }

        return V2Event::class;
    }
}
