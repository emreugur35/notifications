<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request: honours an inbound
 * X-Correlation-Id header or generates a UUID, exposes it on the request
 * (for resources), shares it with the logger, and echoes it on the response.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public const ATTRIBUTE = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get(self::HEADER) ?? (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);

        // Ties into the structured JSON log channel so every log line emitted
        // during this request carries the same correlation id.
        Log::shareContext([self::ATTRIBUTE => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Read the correlation id previously assigned to the request.
     */
    public static function fromRequest(Request $request): ?string
    {
        $value = $request->attributes->get(self::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
