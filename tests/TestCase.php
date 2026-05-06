<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhooksServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            StripeWebhooksServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('stripe-webhooks.webhook_secrets.default', 'whsec_test_default');
        $app['config']->set('stripe-webhooks.webhook_secrets.connect', 'whsec_test_connect');
        $app['config']->set('stripe-webhooks.queue.connection', 'sync');
        $app['config']->set('stripe-webhooks.discover_attributes', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->runPackageMigrations();
    }

    protected function runPackageMigrations(): void
    {
        $this->loadMigrationsFromStubs(__DIR__.'/../database/migrations');
    }

    /**
     * spatie/laravel-package-tools ships migrations as `.php.stub`. They
     * are renamed at publish time, but tests need them loaded directly.
     */
    protected function loadMigrationsFromStubs(string $path): void
    {
        foreach (glob($path.'/*.php.stub') ?: [] as $file) {
            $migration = require $file;
            if (is_object($migration)) {
                $migration->up();
            }
        }
    }
}
