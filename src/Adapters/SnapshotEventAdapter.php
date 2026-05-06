<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Adapters;

use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\SnapshotEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\InvalidSignatureException;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\SignatureVerifier;

class SnapshotEventAdapter
{
    public function __construct(
        private readonly SignatureVerifier $signatureVerifier,
    ) {}

    public function resolve(string $rawPayload, ?string $signatureHeader, string $configKey = 'default'): SnapshotEventDTO
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw InvalidSignatureException::missingHeader();
        }

        $secret = $this->signatureVerifier->resolveSecret($configKey);
        $tolerance = (int) config('stripe-webhooks.tolerance', 300);

        try {
            $event = Webhook::constructEvent($rawPayload, $signatureHeader, $secret, $tolerance);
        } catch (SignatureVerificationException $e) {
            throw InvalidSignatureException::verificationFailed($e->getMessage());
        }

        return new SnapshotEventDTO($event, $rawPayload);
    }
}
