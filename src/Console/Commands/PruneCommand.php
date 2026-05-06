<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Console\Commands;

use Illuminate\Console\Command;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

class PruneCommand extends Command
{
    protected $signature = 'stripe-webhooks:prune
        {--dry-run : Report what would be deleted without touching the database}
        {--status=* : Limit pruning to one or more statuses (processed, failed, dead_letter)}';

    protected $description = 'Delete WebhookCall rows past the retention horizon configured in stripe-webhooks.php.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $statusFilter = (array) $this->option('status');

        $retention = (array) config('stripe-webhooks.retention', []);

        $buckets = [
            WebhookCall::STATUS_PROCESSED => $retention['processed_days'] ?? null,
            WebhookCall::STATUS_FAILED => $retention['failed_days'] ?? null,
            WebhookCall::STATUS_DEAD_LETTER => $retention['dead_letter_days'] ?? null,
        ];

        $totalDeleted = 0;
        $totalKept = 0;

        foreach ($buckets as $status => $days) {
            if ($statusFilter !== [] && ! in_array($status, $statusFilter, true)) {
                continue;
            }

            if ($days === null) {
                $this->components->twoColumnDetail($status, '<fg=gray>retain forever</>');

                continue;
            }

            $cutoff = now()->subDays((int) $days);

            $query = WebhookCall::query()
                ->where('status', $status)
                ->where('received_at', '<', $cutoff);

            $count = $query->count();

            if ($count === 0) {
                $this->components->twoColumnDetail(
                    "{$status} (>{$days}d)",
                    '<fg=gray>nothing to prune</>'
                );

                continue;
            }

            if ($dryRun) {
                $this->components->twoColumnDetail(
                    "{$status} (>{$days}d)",
                    "<fg=yellow>{$count} would be deleted</>"
                );
                $totalKept += $count;

                continue;
            }

            $deleted = $query->delete();
            $totalDeleted += $deleted;

            $this->components->twoColumnDetail(
                "{$status} (>{$days}d)",
                "<fg=green>{$deleted} deleted</>"
            );
        }

        if ($dryRun) {
            $this->components->info("Dry run · {$totalKept} rows would be deleted.");
        } else {
            $this->components->info("Pruned {$totalDeleted} rows.");
        }

        return self::SUCCESS;
    }
}
