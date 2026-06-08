<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseResource extends JsonResource
{
    /**
     * Top-level payload merged into every single-resource response.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'correlation_id' => AssignCorrelationId::fromRequest($request),
        ];
    }
}
