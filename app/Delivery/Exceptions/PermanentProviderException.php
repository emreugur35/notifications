<?php

declare(strict_types=1);

namespace App\Delivery\Exceptions;

/**
 * A non-retryable provider failure: HTTP 4xx (except 429), or a 2xx response
 * missing a messageId. The job should fail fast (no retries).
 */
class PermanentProviderException extends ProviderException {}
