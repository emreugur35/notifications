<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthChecker;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group System
 */
class HealthController
{
    /**
     * Health check
     *
     * Probes database, Redis, and queue connectivity. Returns 200 when all
     * dependencies are reachable, or 503 when any is degraded.
     *
     * @response 200 scenario="Healthy" {"status":"ok","service":"notifications","checks":{"database":{"status":"ok","latency_ms":1.2}}}
     * @response 503 scenario="Degraded" {"status":"degraded","service":"notifications","checks":{"redis":{"status":"error","latency_ms":2001,"error":"Connection refused"}}}
     */
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $checks = $checker->run();
        $healthy = $checker->isHealthy($checks);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'notifications',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
