<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * @mixin Notification
 */
class NotificationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'recipient' => $this->recipient,
            'channel' => $this->channel->value,
            'content' => $this->content,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'idempotency_key' => $this->idempotency_key,
            'provider_message_id' => $this->provider_message_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'delivery_attempts' => DeliveryAttemptResource::collection(
                $this->whenLoaded('deliveryAttempts')
            ),
        ];
    }
}
