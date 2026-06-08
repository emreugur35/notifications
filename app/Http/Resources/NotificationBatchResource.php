<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationBatch;
use Illuminate\Http\Request;

/**
 * @mixin NotificationBatch
 */
class NotificationBatchResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'total_count' => $this->total_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
