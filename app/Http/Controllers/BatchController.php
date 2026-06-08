<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

/**
 * @group Batches
 *
 * Inspect notification batches.
 */
class BatchController
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * Get batch status
     *
     * Returns a batch along with a live, zero-filled per-status rollup of its
     * notifications.
     *
     * @urlParam batch string required The batch UUID. Example: 9b1d...
     *
     * @response scenario="Found" {"data": {"total_count": 3}, "rollup": {"pending": 1, "sent": 2}, "correlation_id": "..."}
     */
    public function show(NotificationBatch $batch): JsonResponse
    {
        return NotificationBatchResource::make($batch)
            ->additional(['rollup' => $this->service->rollup($batch)])
            ->response();
    }
}
