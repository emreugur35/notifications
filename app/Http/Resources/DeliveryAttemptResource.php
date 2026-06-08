<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryAttempt
 */
class DeliveryAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'response_code' => $this->response_code,
            'response_body' => $this->response_body,
            'latency_ms' => $this->latency_ms,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
