<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookProcessed;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;

class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $webhookCallId)
    {
        $this->onConnection(config('stripe-webhooks.queue.connection'));
        $this->onQueue(config('stripe-webhooks.queue.name', 'stripe-webhooks'));
    }

    public function handle(HandlerDiscovery $discovery): void
    {
        /** @var WebhookCall|null $call */
        $call = WebhookCall::query()->find($this->webhookCallId);

        if ($call === null) {
            return;
        }

        if ($call->status === WebhookCall::STATUS_PROCESSED) {
            return;
        }

        $handlers = $discovery->for($call->type);

        if ($handlers === []) {
            $call->update([
                'status' => WebhookCall::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);
            WebhookProcessed::dispatch($call);

            return;
        }

        $call->update(['status' => WebhookCall::STATUS_PROCESSING]);

        foreach ($handlers as $handlerClass) {
            $run = WebhookHandlerRun::query()->create([
                'webhook_call_id' => $call->id,
                'handler_class' => $handlerClass,
                'status' => WebhookHandlerRun::STATUS_PENDING,
            ]);

            RunStripeHandler::dispatch($run->id);
        }
    }
}
