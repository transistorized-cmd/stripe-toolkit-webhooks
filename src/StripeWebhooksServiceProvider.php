<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Stripe\StripeClient;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\InstallCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\MigrateFromSpatieCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands\PruneCommand;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\StripeObjectFetcher;
use TransistorizedCmd\StripeToolkit\Webhooks\Routing\WebhookRouter;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\SdkStripeObjectFetcher;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\SignatureVerifier;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\StripeReconciler;

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

        // Stripe SDK client — bindIf so the user's existing binding wins.
        // Falls back to `services.stripe.secret`, the Laravel convention.
        $this->app->bindIf(StripeClient::class, function (): StripeClient {
            $secret = config('services.stripe.secret');
            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException(
                    'Stripe SDK secret missing — set services.stripe.secret '
                    .'(usually via STRIPE_SECRET in .env) or bind '
                    .StripeClient::class.' explicitly in your container.'
                );
            }

            return new StripeClient($secret);
        });

        // Reconcile facility — bindIf again so users can swap the fetcher
        // (e.g. with a fake in tests, or one that uses Stripe Connect's
        // stripe_account option).
        $this->app->bindIf(StripeObjectFetcher::class, SdkStripeObjectFetcher::class);
        $this->app->singleton(StripeReconciler::class);
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
