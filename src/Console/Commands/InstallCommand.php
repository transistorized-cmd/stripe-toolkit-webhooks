<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'stripe-webhooks:install
        {--force : Overwrite existing config, migrations, and sample handler}';

    protected $description = 'Publish stripe-webhooks config + migrations and scaffold a sample handler.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', [
            '--tag' => 'stripe-toolkit-webhooks-config',
            '--force' => $force,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'stripe-toolkit-webhooks-migrations',
            '--force' => $force,
        ]);

        $this->scaffoldSampleHandler($force);

        $this->components->info('Stripe Toolkit · Webhooks installed.');
        $this->line('  1. Set STRIPE_WEBHOOK_SECRET in your .env');
        $this->line('  2. Register a route: StripeWebhook::route(\'stripe/webhook\');');
        $this->line('  3. Run php artisan migrate');
        $this->line('  4. Edit app/Stripe/Handlers/HandlePaymentIntentSucceeded.php');

        return self::SUCCESS;
    }

    protected function scaffoldSampleHandler(bool $force): void
    {
        if (! function_exists('app_path')) {
            return;
        }

        $target = app_path('Stripe/Handlers/HandlePaymentIntentSucceeded.php');

        if (file_exists($target) && ! $force) {
            $this->components->twoColumnDetail(
                'Sample handler already exists',
                '<fg=yellow>SKIPPED</>'
            );

            return;
        }

        $stub = (string) file_get_contents(__DIR__.'/../../../stubs/handler.stub');

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, recursive: true);
        }

        file_put_contents($target, $stub);

        $this->components->twoColumnDetail(
            'Created '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $target),
            '<fg=green>DONE</>'
        );
    }
}
