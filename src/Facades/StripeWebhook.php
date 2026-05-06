<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Facades;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use TransistorizedCmd\StripeToolkit\Webhooks\Routing\WebhookRouter;

/**
 * @method static Route route(string $path)
 *
 * @see WebhookRouter
 */
class StripeWebhook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WebhookRouter::class;
    }
}
