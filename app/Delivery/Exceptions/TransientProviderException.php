<?php

declare(strict_types=1);

namespace App\Delivery\Exceptions;

/**
 * A retryable provider failure: connection/timeout, HTTP 429, or HTTP 5xx.
 * The job should re-throw this so the queue retries it with backoff.
 */
class TransientProviderException extends ProviderException {}
