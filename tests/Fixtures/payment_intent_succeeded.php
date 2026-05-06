<?php

declare(strict_types=1);

return [
    'id' => 'evt_test_pi_succeeded_001',
    'object' => 'event',
    'api_version' => '2024-06-20',
    'created' => 1714000000,
    'data' => [
        'object' => [
            'id' => 'pi_test_001',
            'object' => 'payment_intent',
            'amount' => 4900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'customer' => 'cus_test_001',
        ],
    ],
    'livemode' => false,
    'pending_webhooks' => 1,
    'request' => null,
    'type' => 'payment_intent.succeeded',
];
