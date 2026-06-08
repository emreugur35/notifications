<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Status;
use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $notification_id
 * @property int $attempt_number
 * @property Status $status
 * @property int|null $response_code
 * @property string|null $response_body
 * @property int|null $latency_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property-read Notification $notification
 */
class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => Status::class,
            'response_code' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    protected static function newFactory(): DeliveryAttemptFactory
    {
        return DeliveryAttemptFactory::new();
    }
}
