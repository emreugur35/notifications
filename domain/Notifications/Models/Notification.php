<?php

declare(strict_types=1);

namespace Domain\Notifications\Models;

use Domain\Notifications\Enums\NotificationChannel;
use Domain\Notifications\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property NotificationChannel $channel
 * @property NotificationStatus $status
 * @property array $payload
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
        ];
    }
}
