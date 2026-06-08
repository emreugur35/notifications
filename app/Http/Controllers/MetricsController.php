<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Metrics\MetricsCollector;
use Illuminate\Http\JsonResponse;

/**
 * @group System
 */
class MetricsController
{
    /**
     * Metrics
     *
     * Operational metrics: queue depth per priority, success/failure counts
     * over a rolling window, throughput (sent/min), and delivery latency
     * percentiles (p50/p95/p99).
     *
     * @response 200 {"window_minutes":5,"queue_depth":{"high":0,"normal":2,"low":10},"counts":{"sent":1200,"failed":3},"throughput_per_min":240.0,"latency_ms":{"p50":42,"p95":110,"p99":190}}
     */
    public function __invoke(MetricsCollector $collector): JsonResponse
    {
        return response()->json($collector->collect());
    }
}
