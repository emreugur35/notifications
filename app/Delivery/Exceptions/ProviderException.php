<?php

declare(strict_types=1);

namespace App\Delivery\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for delivery-provider failures. Carries the HTTP status code (0
 * when there was no response, e.g. a connection error) and the measured
 * latency so the job can record a delivery attempt without re-deriving them.
 */
abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly int $latencyMs = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
