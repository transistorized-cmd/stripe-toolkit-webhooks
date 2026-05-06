<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stripe\StripeObject;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\StripeObjectFetcher;
use TransistorizedCmd\StripeToolkit\Webhooks\Contracts\WebhookEventDTO;
use TransistorizedCmd\StripeToolkit\Webhooks\Events\WebhookReconciled;
use TransistorizedCmd\StripeToolkit\Webhooks\Models\WebhookCall;
use TransistorizedCmd\StripeToolkit\Webhooks\StripeWebhookHandler;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\HandlerDiscovery;
use TransistorizedCmd\StripeToolkit\Webhooks\Support\StripeReconciler;

class FakeFetcher implements StripeObjectFetcher
{
    /** @var array<string,object> */
    public array $byId = [];

    /** @var array<int,string> */
    public array $calledFor = [];

    public function fetch(string $stripeId): object
    {
        $this->calledFor[] = $stripeId;

        return $this->byId[$stripeId] ?? throw new DomainException("no fake for {$stripeId}");
    }
}

class CapturingReconcileHandler extends StripeWebhookHandler
{
    /** @var array<int,object> */
    public static array $observed = [];

    public int $tries = 1;

    public function handle(WebhookEventDTO $event): void
    {
        self::$observed[] = $event->relatedObject();
    }
}

beforeEach(function () {
    CapturingReconcileHandler::$observed = [];
    config()->set('stripe-webhooks.handlers', [
        'payment_intent.succeeded' => [CapturingReconcileHandler::class],
    ]);
    config()->set('stripe-webhooks.discover_attributes', false);
    // Dummy secret so the kit's StripeClient binding resolves without
    // a real API key. Tests bind a fake StripeObjectFetcher anyway so
    // the SDK is never actually called.
    config()->set('services.stripe.secret', 'sk_test_dummy_for_container_resolution');

    $this->app->forgetInstance(HandlerDiscovery::class);
    $this->app->forgetInstance(StripeReconciler::class);
});

it('refetches the related object and re-runs handlers with the fresh state', function () {
    Event::fake([WebhookReconciled::class]);

    $fresh = StripeObject::constructFrom([
        'id' => 'pi_fresh',
        'object' => 'payment_intent',
        'amount' => 9999,
        'currency' => 'eur',
        'status' => 'succeeded',
    ]);

    $fetcher = new FakeFetcher;
    $fetcher->byId['pi_stale'] = $fresh;
    $this->app->instance(StripeObjectFetcher::class, $fetcher);

    $call = WebhookCall::query()->create([
        'stripe_event_id' => 'evt_stale',
        'type' => 'payment_intent.succeeded',
        'livemode' => false,
        'api_version' => '2024-06-20',
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => [
            'id' => 'evt_stale',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_stale', 'amount' => 100, 'status' => 'requires_payment_method']],
        ],
        'related_object_id' => 'pi_stale',
        'status' => WebhookCall::STATUS_FAILED,
        'received_at' => now()->subHours(2),
    ]);

    /** @var StripeReconciler $reconciler */
    $reconciler = app(StripeReconciler::class);

    $dto = $reconciler->reconcile($call);

    expect($fetcher->calledFor)->toBe(['pi_stale'])
        ->and(CapturingReconcileHandler::$observed)->toHaveCount(1);

    $observed = CapturingReconcileHandler::$observed[0];
    expect($observed->id)->toBe('pi_fresh')
        ->and($observed->amount)->toBe(9999)
        ->and($observed->status)->toBe('succeeded');

    expect($dto->relatedObject()->id)->toBe('pi_fresh');

    Event::assertDispatched(WebhookReconciled::class, function (WebhookReconciled $e) use ($call) {
        return $e->webhookCall->id === $call->id
            && $e->freshRelatedObject->id === 'pi_fresh';
    });
});

it('throws when the call has no related_object_id', function () {
    $call = WebhookCall::query()->create([
        'stripe_event_id' => 'evt_no_related',
        'type' => 'something.weird',
        'livemode' => false,
        'api_version' => null,
        'source' => 'snapshot',
        'config_key' => 'default',
        'payload' => ['stub' => true],
        'related_object_id' => null,
        'status' => WebhookCall::STATUS_FAILED,
        'received_at' => now(),
    ]);

    /** @var StripeReconciler $reconciler */
    $reconciler = app(StripeReconciler::class);

    expect(fn () => $reconciler->reconcile($call))
        ->toThrow(DomainException::class, 'no related_object_id');
});

it('exposes a fetchObject() shortcut for app code that just needs the live state', function () {
    $fresh = StripeObject::constructFrom(['id' => 'cs_test_live', 'payment_status' => 'paid']);

    $fetcher = new FakeFetcher;
    $fetcher->byId['cs_test_live'] = $fresh;
    $this->app->instance(StripeObjectFetcher::class, $fetcher);

    /** @var StripeReconciler $reconciler */
    $reconciler = app(StripeReconciler::class);

    expect($reconciler->fetchObject('cs_test_live'))->toBe($fresh)
        ->and($fetcher->calledFor)->toBe(['cs_test_live']);
});
