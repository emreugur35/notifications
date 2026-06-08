<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request: honours an inbound
 * X-Correlation-Id header or generates a UUID, exposes it on the request (for
 * resources), and echoes it on the response.
 *
 * The id is published to Laravel's Context, which (a) auto-injects it into the
 * "extra" of every structured log line and (b) is serialized into any queued
 * job dispatched during the request and rehydrated when the job runs. That is
 * how request → job → provider attempt all share one id.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public const ATTRIBUTE = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get(self::HEADER) ?? (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
        Context::add(self::ATTRIBUTE, $correlationId);

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
