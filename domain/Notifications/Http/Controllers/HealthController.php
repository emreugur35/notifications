<?php

declare(strict_types=1);

namespace Domain\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController
{
    /**
     * Liveness probe. Returns 200 when the application is up.
     *
     * @group System
     *
     * @response 200 {"status":"ok","service":"notifications"}
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'notifications',
        ]);
    }
}
