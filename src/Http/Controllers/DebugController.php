<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookHandlerRun;

class DebugController
{
    public function index(Request $request): View
    {
        $perPage = (int) config('stripe-webhooks.debug.per_page', 25);

        $query = WebhookCall::query()
            ->orderByDesc('id');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('type')->toString()) {
            $query->where('type', 'like', "%{$type}%");
        }
        if ($configKey = $request->string('config_key')->toString()) {
            $query->where('config_key', $configKey);
        }

        $calls = $query->paginate($perPage)->withQueryString();
        $callIds = $calls->getCollection()->pluck('id');

        $runCounts = WebhookHandlerRun::query()
            ->whereIn('webhook_call_id', $callIds)
            ->selectRaw('webhook_call_id, status, count(*) as total')
            ->groupBy('webhook_call_id', 'status')
            ->get()
            ->groupBy('webhook_call_id');

        return view('stripe-webhooks::debug.index', [
            'calls' => $calls,
            'runCounts' => $runCounts,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'type' => $request->string('type')->toString(),
                'config_key' => $request->string('config_key')->toString(),
            ],
            'autoRefresh' => (int) config('stripe-webhooks.debug.auto_refresh_seconds', 5),
            'totals' => $this->totals(),
            'duplicateFromId' => (int) $request->integer('duplicate') ?: null,
        ]);
    }

    public function show(int $id): View
    {
        $call = WebhookCall::query()->with('handlerRuns')->findOrFail($id);

        return view('stripe-webhooks::debug.show', [
            'call' => $call,
            'autoRefresh' => (int) config('stripe-webhooks.debug.auto_refresh_seconds', 5),
        ]);
    }

    public function form(Request $request): View
    {
        return view('stripe-webhooks::debug.form', [
            'configKeys' => array_keys((array) config('stripe-webhooks.webhook_secrets', [])),
            'eventTypes' => self::commonEventTypes(),
            'result' => null,
            'prefill' => $this->prefillFrom($request),
        ]);
    }

    /**
     * Build a defaults array used by the form to populate fields. Driven
     * by the optional `?from={id}` query param (duplicate-an-existing-call
     * mode) plus any direct query overrides.
     *
     * @return array<string,mixed>
     */
    protected function prefillFrom(Request $request): array
    {
        $defaults = [
            'event_type' => 'payment_intent.succeeded',
            'config_key' => 'default',
            'event_id' => '',
            'object_id' => '',
            'amount' => 4900,
            'currency' => 'eur',
            'customer' => 'cus_smoke_001',
            'livemode' => false,
            'tamper' => false,
            'timestamp_skew' => 0,
            'data_json' => '',
            'source_id' => null,
        ];

        $fromId = (int) $request->integer('from');
        if ($fromId > 0) {
            /** @var WebhookCall|null $source */
            $source = WebhookCall::query()->find($fromId);
            if ($source !== null) {
                $payload = $source->payload->toArray();
                $object = $payload['data']['object'] ?? [];

                $defaults['event_type'] = $source->type ?: $defaults['event_type'];
                $defaults['config_key'] = $source->config_key ?: $defaults['config_key'];
                $defaults['livemode'] = (bool) $source->livemode;
                $defaults['customer'] = (string) ($object['customer'] ?? $defaults['customer']);
                $defaults['currency'] = (string) ($object['currency'] ?? $defaults['currency']);
                $defaults['amount'] = (int) (
                    $object['amount']
                    ?? $object['amount_total']
                    ?? $object['amount_due']
                    ?? $object['amount_refunded']
                    ?? $defaults['amount']
                );
                $defaults['data_json'] = (string) json_encode(
                    $object,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $defaults['source_id'] = $source->id;
            }
        }

        foreach (['event_type', 'config_key', 'event_id', 'object_id', 'currency', 'customer'] as $key) {
            if ($request->filled($key)) {
                $defaults[$key] = (string) $request->input($key);
            }
        }
        if ($request->filled('amount')) {
            $defaults['amount'] = (int) $request->input('amount');
        }

        return $defaults;
    }

    public function send(Request $request): View
    {
        $eventType = trim((string) $request->input('event_type', 'payment_intent.succeeded'));
        $configKey = trim((string) $request->input('config_key', 'default'));
        $forceEventId = trim((string) $request->input('event_id', ''));
        $forceObjectId = trim((string) $request->input('object_id', ''));
        $amount = (int) $request->input('amount', 4900);
        $currency = strtolower(trim((string) $request->input('currency', 'eur'))) ?: 'eur';
        $customer = trim((string) $request->input('customer', 'cus_smoke_001')) ?: 'cus_smoke_001';
        $livemode = $request->boolean('livemode');
        $tamper = $request->boolean('tamper');
        $timestampSkew = (int) $request->input('timestamp_skew', 0);
        $dataJson = (string) $request->input('data_json', '');

        $eventId = $forceEventId !== '' ? $forceEventId : 'evt_dbg_'.bin2hex(random_bytes(4));
        $objectId = $forceObjectId !== '' ? $forceObjectId : self::defaultObjectId($eventType);

        $prefill = [
            'event_type' => $eventType,
            'config_key' => $configKey,
            'event_id' => $forceEventId,
            'object_id' => $forceObjectId,
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customer,
            'livemode' => $livemode,
            'tamper' => $tamper,
            'timestamp_skew' => $timestampSkew,
            'data_json' => $dataJson,
            'source_id' => null,
        ];

        $customObject = null;
        $jsonError = null;
        if (trim($dataJson) !== '') {
            $decoded = json_decode($dataJson, true);
            if (! is_array($decoded)) {
                $jsonError = 'data_json is not valid JSON: '.json_last_error_msg();
            } else {
                $customObject = $decoded;
            }
        }

        $payload = self::buildPayload(
            eventId: $eventId,
            eventType: $eventType,
            livemode: $livemode,
            objectId: $objectId,
            amount: $amount,
            currency: $currency,
            customer: $customer,
            customObject: $customObject,
        );

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $secret = (string) config("stripe-webhooks.webhook_secrets.{$configKey}", '');
        $signingSecret = $tamper ? 'whsec_intentionally_wrong' : $secret;
        $timestamp = time() + $timestampSkew;

        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $signingSecret);
        $signatureHeader = "t={$timestamp},v1={$signature}";

        $url = $configKey !== 'default'
            ? url("/stripe/webhook/{$configKey}")
            : url('/stripe/webhook');

        $secretMissing = $secret === '' && ! $tamper;

        $result = [
            'request_url' => $url,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'config_key' => $configKey,
            'tampered' => $tamper,
            'timestamp_skew' => $timestampSkew,
            'body' => $body,
            'http_status' => null,
            'http_body' => null,
            'error' => null,
            'secret_missing' => $secretMissing,
        ];

        if ($jsonError !== null) {
            $result['error'] = $jsonError;

            return view('stripe-webhooks::debug.form', $this->formViewData($result, $prefill));
        }

        if ($secretMissing) {
            $result['error'] = "No secret configured for [{$configKey}] — add it under stripe-webhooks.webhook_secrets.";

            return view('stripe-webhooks::debug.form', $this->formViewData($result, $prefill));
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Stripe-Signature' => $signatureHeader,
            ])
                ->withBody($body, 'application/json')
                ->timeout(10)
                ->post($url);

            $result['http_status'] = $response->status();
            $result['http_body'] = $response->body();
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return view('stripe-webhooks::debug.form', $this->formViewData($result, $prefill));
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $prefill
     * @return array<string,mixed>
     */
    protected function formViewData(array $result, array $prefill): array
    {
        return [
            'configKeys' => array_keys((array) config('stripe-webhooks.webhook_secrets', [])),
            'eventTypes' => self::commonEventTypes(),
            'result' => $result,
            'prefill' => $prefill,
        ];
    }

    /**
     * @return array<int,string>
     */
    protected static function commonEventTypes(): array
    {
        return [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'charge.succeeded',
            'charge.refunded',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'checkout.session.completed',
        ];
    }

    protected static function defaultObjectId(string $eventType): string
    {
        $rand = bin2hex(random_bytes(4));

        return match (true) {
            str_starts_with($eventType, 'payment_intent.') => "pi_{$rand}",
            str_starts_with($eventType, 'charge.') => "ch_{$rand}",
            str_starts_with($eventType, 'invoice.') => "in_{$rand}",
            str_starts_with($eventType, 'customer.subscription.') => "sub_{$rand}",
            str_starts_with($eventType, 'checkout.session.') => "cs_{$rand}",
            str_starts_with($eventType, 'customer.') => "cus_{$rand}",
            default => "obj_{$rand}",
        };
    }

    /**
     * @param  array<string,mixed>|null  $customObject  Override the inner
     *                                                  `data.object` with
     *                                                  user-supplied JSON.
     * @return array<string,mixed>
     */
    protected static function buildPayload(
        string $eventId,
        string $eventType,
        bool $livemode,
        string $objectId,
        int $amount,
        string $currency,
        string $customer,
        ?array $customObject = null,
    ): array {
        $object = $customObject ?? self::buildObject($eventType, $objectId, $amount, $currency, $customer);

        return [
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => [
                'object' => $object,
            ],
            'livemode' => $livemode,
            'pending_webhooks' => 1,
            'request' => null,
            'type' => $eventType,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected static function buildObject(
        string $eventType,
        string $objectId,
        int $amount,
        string $currency,
        string $customer,
    ): array {
        if (str_starts_with($eventType, 'payment_intent.')) {
            return [
                'id' => $objectId,
                'object' => 'payment_intent',
                'amount' => $amount,
                'currency' => $currency,
                'status' => str_ends_with($eventType, '.succeeded') ? 'succeeded' : 'requires_payment_method',
                'customer' => $customer,
            ];
        }

        if (str_starts_with($eventType, 'charge.')) {
            return [
                'id' => $objectId,
                'object' => 'charge',
                'amount' => $amount,
                'amount_refunded' => str_ends_with($eventType, '.refunded') ? $amount : 0,
                'currency' => $currency,
                'customer' => $customer,
                'status' => 'succeeded',
            ];
        }

        if (str_starts_with($eventType, 'invoice.')) {
            return [
                'id' => $objectId,
                'object' => 'invoice',
                'amount_due' => $amount,
                'amount_paid' => str_ends_with($eventType, '.payment_succeeded') ? $amount : 0,
                'currency' => $currency,
                'customer' => $customer,
                'status' => str_ends_with($eventType, '.payment_succeeded') ? 'paid' : 'open',
            ];
        }

        if (str_starts_with($eventType, 'customer.subscription.')) {
            return [
                'id' => $objectId,
                'object' => 'subscription',
                'status' => 'active',
                'customer' => $customer,
                'current_period_start' => time(),
                'current_period_end' => time() + 30 * 24 * 3600,
            ];
        }

        if (str_starts_with($eventType, 'checkout.session.')) {
            return [
                'id' => $objectId,
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => $currency,
                'customer' => $customer,
                'payment_status' => 'paid',
            ];
        }

        return [
            'id' => $objectId,
            'object' => explode('.', $eventType)[0] ?? 'object',
            'customer' => $customer,
        ];
    }

    /**
     * @return array<string,int>
     */
    protected function totals(): array
    {
        return WebhookCall::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * Trigger a real Stripe event via the API. Each scenario hits the
     * Stripe SDK to create the resource that produces the desired
     * webhook(s); Stripe then delivers them through whatever endpoint
     * is configured (typically `stripe listen` in dev). Distinct from
     * `_send` which only signs payloads locally and never reaches
     * Stripe.
     *
     * Available scenarios:
     *   - success            → pm_card_visa, charge.succeeded
     *   - declined           → pm_card_chargeDeclined, charge.failed
     *   - insufficient_funds → pm_card_chargeDeclinedInsufficientFunds
     *   - requires_action    → pm_card_authenticationRequired
     *   - refund_last        → refunds the most recent successful charge
     *   - customer_created   → creates a Customer (no payment, tests inapplicable)
     */
    public function trigger(Request $request): RedirectResponse
    {
        $scenario = (string) $request->input('scenario');
        $client = app(StripeClient::class);

        try {
            $message = match ($scenario) {
                'success' => $this->triggerPayment($client, 'pm_card_visa', 'Successful charge'),
                'declined' => $this->triggerPayment($client, 'pm_card_chargeDeclined', 'Generic decline'),
                'insufficient_funds' => $this->triggerPayment($client, 'pm_card_chargeDeclinedInsufficientFunds', 'Insufficient funds'),
                'requires_action' => $this->triggerPayment($client, 'pm_card_authenticationRequired', '3DS required'),
                'refund_last' => $this->triggerRefund($client),
                'customer_created' => $this->triggerCustomer($client),
                default => throw new \DomainException("Unknown scenario [{$scenario}]."),
            };

            return redirect()->back()->with('trigger_message', $message);
        } catch (\Throwable $e) {
            return redirect()->back()->with('trigger_error', $e->getMessage());
        }
    }

    protected function triggerPayment(StripeClient $client, string $paymentMethod, string $label): string
    {
        $intent = $client->paymentIntents->create([
            'amount' => 420,
            'currency' => 'eur',
            'payment_method' => $paymentMethod,
            'payment_method_types' => ['card'],
            'confirm' => true,
            'description' => "Triggered from debug inspector — {$label}",
            'metadata' => ['source' => 'stripe-toolkit-webhooks-inspector'],
        ]);

        return "{$label}: PaymentIntent {$intent->id} → status: {$intent->status}";
    }

    protected function triggerRefund(StripeClient $client): string
    {
        $charges = $client->charges->all(['limit' => 25]);

        foreach ($charges->data as $charge) {
            if (($charge->paid ?? false) && (int) ($charge->amount_refunded ?? 0) < (int) ($charge->amount ?? 0)) {
                $refund = $client->refunds->create(['charge' => $charge->id]);

                return "Refunded charge {$charge->id} for {$charge->amount} {$charge->currency} → refund {$refund->id}";
            }
        }

        throw new \DomainException(
            'No refundable charge found. Trigger a Successful payment first, then come back.'
        );
    }

    protected function triggerCustomer(StripeClient $client): string
    {
        $customer = $client->customers->create([
            'email' => 'demo+'.bin2hex(random_bytes(3)).'@example.test',
            'description' => 'Created from debug inspector trigger',
            'metadata' => ['source' => 'stripe-toolkit-webhooks-inspector'],
        ]);

        return "Created Customer {$customer->id} (no payment — tests the n/a indicator)";
    }
}
