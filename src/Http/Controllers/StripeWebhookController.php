<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TransistorizedCmd\StripeToolkit\Webhooks\Adapters\EventResolver;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookReceived;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\InvalidSignatureException;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnrecognizedPayloadException;
use TransistorizedCmd\StripeToolkit\Webhooks\Exceptions\UnsupportedSdkVersionException;
use TransistorizedCmd\StripeToolkit\Webhooks\Jobs\ProcessStripeWebhook;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;

class StripeWebhookController
{
    public function __construct(private readonly EventResolver $resolver) {}

    public function __invoke(Request $request, ?string $configKey = null): JsonResponse
    {
        $configKey ??= 'default';
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature');

        try {
            $dto = $this->resolver->resolve($rawPayload, $signatureHeader, $configKey);
        } catch (InvalidSignatureException|UnrecognizedPayloadException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (UnsupportedSdkVersionException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        $payloadArray = json_decode($rawPayload, true) ?: [];
        [$call, $created] = $this->persist($dto, $configKey, $payloadArray);

        if (! $created) {
            // Either Stripe re-delivered (idempotency) or a parallel request
            // won the UNIQUE race. Either way, do not re-dispatch — the
            // request that did create the row owns the processing job.
            if ($call->isProcessed()) {
                return new JsonResponse(['status' => 'duplicate'], 200);
            }

            return new JsonResponse(['status' => 'in_progress', 'id' => $call->id], 200);
        }

        WebhookReceived::dispatch($call);
        ProcessStripeWebhook::dispatch($call->id);

        return new JsonResponse(['status' => 'queued', 'id' => $call->id], 200);
    }

    /**
     * Insert a row keyed by `event.id` and return `[call, created]`.
     * On UNIQUE collision, look up the existing row and return it with
     * `created = false` so the caller can decide whether to dispatch.
     *
     * @param  array<string,mixed>  $payloadArray
     * @return array{0: WebhookCall, 1: bool}
     */
    protected function persist(WebhookEventDTO $dto, string $configKey, array $payloadArray): array
    {
        $relatedObjectId = $payloadArray['related_object']['id']
            ?? $payloadArray['data']['object']['id']
            ?? null;

        try {
            $call = WebhookCall::query()->create([
                'stripe_event_id' => $dto->id(),
                'type' => $dto->type(),
                'livemode' => $dto->livemode(),
                'api_version' => $dto->apiVersion(),
                'source' => $dto->sourceFormat()->value,
                'config_key' => $configKey,
                'payload' => $payloadArray,
                'related_object_id' => $relatedObjectId,
                'status' => WebhookCall::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            return [$call, true];
        } catch (QueryException $e) {
            $existing = WebhookCall::query()
                ->where('stripe_event_id', $dto->id())
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            throw $e;
        }
    }
}
