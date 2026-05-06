<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\SnapshotEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\DTO\ThinEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookDeadLettered;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookHandlerFailed;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookProcessed;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;

class RunStripeHandler implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Hard ceiling for queue-level retries. Per-handler tries are tracked
     * on the WebhookHandlerRun row and respected explicitly inside
     * `handle()`; this just keeps the queue from giving up before we do.
     */
    public int $tries = 50;

    public function __construct(public readonly int $handlerRunId)
    {
        $this->onConnection(config('stripe-webhooks.queue.connection'));
        $this->onQueue(config('stripe-webhooks.queue.name', 'stripe-webhooks'));
    }

    public function handle(): void
    {
        /** @var WebhookHandlerRun|null $run */
        $run = WebhookHandlerRun::query()->find($this->handlerRunId);
        if ($run === null) {
            return;
        }

        if ($run->status === WebhookHandlerRun::STATUS_PROCESSED) {
            return;
        }

        /** @var WebhookCall $call */
        $call = $run->webhookCall;

        $run->forceFill([
            'status' => WebhookHandlerRun::STATUS_RUNNING,
            'attempts' => $run->attempts + 1,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        $handlerClass = $run->handler_class;

        if (! class_exists($handlerClass)) {
            $this->markFailed($run, $call, new \RuntimeException("Handler [{$handlerClass}] not found."), WebhookHandlerRun::ERROR_NOT_FOUND);

            return;
        }

        /** @var StripeWebhookHandler $handler */
        $handler = app($handlerClass);

        try {
            $handler->handle($this->rebuildDTO($call));
        } catch (Throwable $e) {
            $this->markFailed($run, $call, $e, $this->categorize($e), $handler);

            return;
        }

        $run->forceFill([
            'status' => WebhookHandlerRun::STATUS_PROCESSED,
            'finished_at' => now(),
            'last_error' => null,
            'last_error_category' => null,
        ])->save();

        $this->maybeMarkCallProcessed($call);
    }

    protected function markFailed(
        WebhookHandlerRun $run,
        WebhookCall $call,
        Throwable $e,
        string $category,
        ?StripeWebhookHandler $handler = null,
    ): void {
        $maxTries = $handler->tries ?? 3;

        if ($run->attempts >= $maxTries) {
            $run->forceFill([
                'status' => WebhookHandlerRun::STATUS_DEAD_LETTER,
                'finished_at' => now(),
                'last_error' => $this->truncate((string) $e),
                'last_error_category' => $category,
            ])->save();

            WebhookHandlerFailed::dispatch($call, $run, $e);
            WebhookDeadLettered::dispatch($call, $run);

            $call->update(['status' => WebhookCall::STATUS_DEAD_LETTER]);

            return;
        }

        $run->forceFill([
            'status' => WebhookHandlerRun::STATUS_FAILED,
            'last_error' => $this->truncate((string) $e),
            'last_error_category' => $category,
        ])->save();

        WebhookHandlerFailed::dispatch($call, $run, $e);

        $delay = $this->resolveBackoff($handler, $run->attempts);
        $this->release($delay);
    }

    protected function maybeMarkCallProcessed(WebhookCall $call): void
    {
        $unfinished = WebhookHandlerRun::query()
            ->where('webhook_call_id', $call->id)
            ->whereNotIn('status', [
                WebhookHandlerRun::STATUS_PROCESSED,
                WebhookHandlerRun::STATUS_DEAD_LETTER,
            ])
            ->exists();

        if ($unfinished) {
            return;
        }

        $anyDeadLetter = WebhookHandlerRun::query()
            ->where('webhook_call_id', $call->id)
            ->where('status', WebhookHandlerRun::STATUS_DEAD_LETTER)
            ->exists();

        if ($anyDeadLetter) {
            $call->update(['status' => WebhookCall::STATUS_DEAD_LETTER]);

            return;
        }

        $call->update([
            'status' => WebhookCall::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        WebhookProcessed::dispatch($call->fresh());
    }

    protected function rebuildDTO(WebhookCall $call): WebhookEventDTO
    {
        $payload = $call->payload->toArray();

        return $call->source === WebhookCall::SOURCE_THIN
            ? ThinEventDTO::fromArray($payload)
            : SnapshotEventDTO::fromArray($payload);
    }

    protected function resolveBackoff(?StripeWebhookHandler $handler, int $attempt): int
    {
        $backoff = $handler->backoff ?? [60, 300, 900];

        if ($backoff === []) {
            return 60;
        }

        $index = max(0, min($attempt - 1, count($backoff) - 1));

        return (int) $backoff[$index];
    }

    protected function categorize(Throwable $e): string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => WebhookHandlerRun::ERROR_NOT_FOUND,
            $e instanceof ValidationException => WebhookHandlerRun::ERROR_SCHEMA_MISMATCH,
            $e instanceof \DomainException => WebhookHandlerRun::ERROR_BUSINESS_RULE,
            default => WebhookHandlerRun::ERROR_UNKNOWN,
        };
    }

    protected function truncate(string $value, int $max = 65000): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
