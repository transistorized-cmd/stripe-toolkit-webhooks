<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests\Fixtures;

/**
 * Webhook payloads modelled on the structures Stripe documents.
 * Snapshot:  docs.stripe.com/api/events/object
 * Thin:      docs.stripe.com/changelog/acacia/2024-09-30/api-v2-thin-events
 */
final class Fixtures
{
    public static function snapshotPaymentIntentSucceeded(?string $id = null, ?string $accountId = null): array
    {
        $payload = [
            'id' => $id ?? 'evt_test_3PZ8aBC2eZvKYlo2xC1aBcD4',
            'object' => 'event',
            'api_version' => '2025-06-30.basil',
            'created' => 1730000000,
            'data' => [
                'object' => [
                    'id' => 'pi_3PZ8aBC2eZvKYlo20HsXY3aB',
                    'object' => 'payment_intent',
                    'amount' => 4200,
                    'currency' => 'eur',
                    'status' => 'succeeded',
                    'customer' => 'cus_QXYZabc123',
                    'metadata' => ['order_id' => '8842'],
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => [
                'id' => 'req_aBcDeFgHiJkLmN',
                'idempotency_key' => null,
            ],
            'type' => 'payment_intent.succeeded',
        ];

        if ($accountId !== null) {
            $payload['account'] = $accountId;
        }

        return $payload;
    }

    public static function thinV1CustomerCreated(?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_test_65R9Ijk8dKEYZcXeRWn16R9A7j1FSQ3w3TGDPLLGSM4CW0',
            'object' => 'v2.core.event',
            'type' => 'v1.customer.created',
            'livemode' => false,
            'created' => '2025-09-17T06:20:52.246Z',
            'related_object' => [
                'id' => 'cus_QXYZabc123',
                'type' => 'customer',
                'url' => '/v1/customers/cus_QXYZabc123',
            ],
            'reason' => [
                'type' => 'request',
                'request' => [
                    'id' => 'req_aBcDeFgHiJkLmN',
                    'idempotency_key' => null,
                ],
            ],
        ];
    }

    public static function unknownObject(): array
    {
        return [
            'id' => 'evt_unknown',
            'object' => 'something_unexpected',
            'type' => 'unknown.event',
        ];
    }
}
