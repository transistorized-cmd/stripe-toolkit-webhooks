<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class StripeEvent
{
    public function __construct(public readonly string $type) {}
}
