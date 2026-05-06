<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Models;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property bool $livemode
 * @property string|null $api_version
 * @property string $source
 * @property string|null $config_key
 * @property ArrayObject<string,mixed> $payload
 * @property string|null $related_object_id
 * @property string $status
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int,WebhookHandlerRun> $handlerRuns
 */
class WebhookCall extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const SOURCE_SNAPSHOT = 'snapshot';

    public const SOURCE_THIN = 'thin';

    protected $guarded = [];

    protected $casts = [
        'livemode' => 'bool',
        'payload' => AsArrayObject::class,
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('stripe-webhooks.tables.webhook_calls', 'stripe_webhook_calls');
    }

    public function handlerRuns(): HasMany
    {
        return $this->hasMany(WebhookHandlerRun::class);
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PROCESSED,
            self::STATUS_DEAD_LETTER,
        ], true);
    }
}
