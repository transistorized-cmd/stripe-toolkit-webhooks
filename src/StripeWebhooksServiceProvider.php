<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\InstallCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\MigrateFromSpatieCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\PruneCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Routing\WebhookRouter;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\SignatureVerifier;

class StripeWebhooksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('stripe-toolkit-webhooks')
            ->hasConfigFile('stripe-webhooks')
            ->hasMigrations([
                'create_stripe_webhook_calls_table',
                'create_stripe_webhook_handler_runs_table',
            ])
            ->hasCommands([
                InstallCommand::class,
                MigrateFromSpatieCommand::class,
                PruneCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SignatureVerifier::class);
        $this->app->singleton(HandlerDiscovery::class);
        $this->app->singleton(WebhookRouter::class);
    }

    public function packageBooted(): void
    {
        WebhookRouter::registerMacro();

        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'stripe-webhooks'
        );

        if ($this->debugInspectorEnabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/debug.php');
        }
    }

    /**
     * Inspector defaults: ON in non-production, OFF otherwise. Set
     * `STRIPE_WEBHOOKS_DEBUG=true|false` (or `stripe-webhooks.debug.enabled`)
     * to override.
     */
    protected function debugInspectorEnabled(): bool
    {
        $explicit = config('stripe-webhooks.debug.enabled');

        if (is_bool($explicit)) {
            return $explicit;
        }

        return ! $this->app->environment('production');
    }
}
