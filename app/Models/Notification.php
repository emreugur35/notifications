<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $batch_id
 * @property string $recipient
 * @property Channel $channel
 * @property string $content
 * @property Priority $priority
 * @property Status $status
 * @property string|null $idempotency_key
 * @property string|null $provider_message_id
 * @property Carbon|null $scheduled_at
 * @property int $attempts
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationBatch|null $batch
 * @property-read Collection<int, DeliveryAttempt> $deliveryAttempts
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'priority' => Priority::class,
            'status' => Status::class,
            'scheduled_at' => 'datetime',
            'attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /**
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
