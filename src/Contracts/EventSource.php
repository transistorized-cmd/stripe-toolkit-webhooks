<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Contracts;

enum EventSource: string
{
    case Snapshot = 'snapshot';
    case Thin = 'thin';
}
