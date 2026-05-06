<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

class MigrateFromSpatieCommand extends Command
{
    protected $signature = 'stripe-webhooks:migrate-from-spatie
        {--dry-run : Report what would be inserted without touching the database}
        {--source-table=webhook_calls : The Spatie source table name}
        {--source-name=stripe : The `name` filter on the Spatie table}
        {--batch-size=500 : How many rows to process per batch}
        {--since= : Only import rows received_at >= this date (YYYY-MM-DD)}';

    protected $description = 'Import Stripe webhook history from a spatie/laravel-stripe-webhooks installation.';

    public function handle(): int
    {
        $sourceTable = (string) $this->option('source-table');
        $sourceName = (string) $this->option('source-name');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $since = $this->option('since');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->sourceTableExists($sourceTable)) {
            $this->components->error("Source table [{$sourceTable}] not found on the default connection.");

            return self::FAILURE;
        }

        $stats = ['imported' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0];

        $base = DB::table($sourceTable)
            ->where('name', $sourceName)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderBy('id');

        $total = $base->count();
        if ($total === 0) {
            $this->components->info("No rows found in [{$sourceTable}] (name={$sourceName}).");

            return self::SUCCESS;
        }

        $this->components->info(($dryRun ? 'Dry run · ' : '')."Importing up to {$total} rows from {$sourceTable}…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $base->chunkById($batchSize, function ($rows) use (&$stats, $dryRun, $bar) {
            foreach ($rows as $row) {
                $this->importRow($row, $stats, $dryRun);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('imported', "<fg=green>{$stats['imported']}</>");
        $this->components->twoColumnDetail('skipped (duplicate event_id)', "<fg=yellow>{$stats['skipped_duplicate']}</>");
        $this->components->twoColumnDetail('skipped (no event_id in payload)', "<fg=red>{$stats['skipped_invalid']}</>");
        $this->components->info($dryRun ? 'Dry run complete — nothing was written.' : 'Migration complete.');
        $this->line('  Note: handlers are NOT re-run for migrated rows. Use the Pro replay command to re-process if needed.');

        return self::SUCCESS;
    }

    protected function sourceTableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,int>  $stats
     */
    protected function importRow(object $row, array &$stats, bool $dryRun): void
    {
        $payload = json_decode($row->payload ?? '', true);
        if (! is_array($payload) || ! isset($payload['id'], $payload['type'])) {
            $stats['skipped_invalid']++;

            return;
        }

        if (WebhookCall::query()->where('stripe_event_id', $payload['id'])->exists()) {
            $stats['skipped_duplicate']++;

            return;
        }

        if ($dryRun) {
            $stats['imported']++;

            return;
        }

        $relatedObjectId = $payload['data']['object']['id'] ?? null;
        $exception = property_exists($row, 'exception') ? $row->exception : null;
        $hasFailure = is_string($exception) && $exception !== '';
        $sourceName = property_exists($row, 'name') ? (string) $row->name : 'default';

        try {
            WebhookCall::query()->create([
                'stripe_event_id' => $payload['id'],
                'type' => $payload['type'],
                'livemode' => (bool) ($payload['livemode'] ?? false),
                'api_version' => $payload['api_version'] ?? null,
                'source' => 'snapshot',
                'config_key' => $sourceName,
                'payload' => $payload,
                'related_object_id' => $relatedObjectId,
                'status' => $hasFailure ? WebhookCall::STATUS_DEAD_LETTER : WebhookCall::STATUS_PROCESSED,
                'received_at' => $row->created_at,
                'processed_at' => $hasFailure ? null : $row->updated_at,
            ]);
            $stats['imported']++;
        } catch (QueryException) {
            // Race / dupe between count and insert — count as duplicate.
            $stats['skipped_duplicate']++;
        }
    }
}
