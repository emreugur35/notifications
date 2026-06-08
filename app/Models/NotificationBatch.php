<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationBatchFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $name
 * @property int $total_count
 * @property int $pending_count
 * @property int $queued_count
 * @property int $processing_count
 * @property int $sent_count
 * @property int $delivered_count
 * @property int $failed_count
 * @property int $cancelled_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Notification> $notifications
 */
class NotificationBatch extends Model
{
    /** @use HasFactory<NotificationBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notification_batches';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'pending_count' => 'integer',
            'queued_count' => 'integer',
            'processing_count' => 'integer',
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'cancelled_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    protected static function newFactory(): NotificationBatchFactory
    {
        return NotificationBatchFactory::new();
    }
}
