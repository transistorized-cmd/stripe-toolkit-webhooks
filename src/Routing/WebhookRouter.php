<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use TransistorizedCmd\StripeToolkit\Webhooks\Http\Controllers\StripeWebhookController;

class WebhookRouter
{
    /**
     * Register the StripeWebhook macro on Laravel's router. Call
     * `Route::stripeWebhook($path)` (or via the StripeWebhook facade).
     */
    public static function registerMacro(): void
    {
        if (! Router::hasMacro('stripeWebhook')) {
            Router::macro('stripeWebhook', function (string $path) {
                /** @var Router $this */
                return $this->post($path, StripeWebhookController::class)
                    ->middleware(config('stripe-webhooks.route.middleware', ['api']));
            });
        }
    }

    /**
     * Register a webhook POST route at `$path` resolved by the kit's
     * controller. Returns the registered Route for further chaining.
     *
     * If `$path` contains a `{configKey}` placeholder it will be passed
     * through to the controller for multi-secret routing.
     */
    public function route(string $path): Route
    {
        return RouteFacade::post($path, StripeWebhookController::class)
            ->middleware(config('stripe-webhooks.route.middleware', ['api']));
    }
}
