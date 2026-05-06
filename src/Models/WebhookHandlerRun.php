<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $webhook_call_id
 * @property string $handler_class
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $last_error_category
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WebhookCall $webhookCall
 */
class WebhookHandlerRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const ERROR_NOT_FOUND = 'not_found';

    public const ERROR_SCHEMA_MISMATCH = 'schema_mismatch';

    public const ERROR_BUSINESS_RULE = 'business_rule';

    public const ERROR_TRANSIENT = 'transient';

    public const ERROR_UNKNOWN = 'unknown';

    protected $guarded = [];

    protected $casts = [
        'attempts' => 'int',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('stripe-webhooks.tables.handler_runs', 'stripe_webhook_handler_runs');
    }

    public function webhookCall(): BelongsTo
    {
        return $this->belongsTo(WebhookCall::class);
    }
}
