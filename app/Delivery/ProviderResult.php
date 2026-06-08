<?php

declare(strict_types=1);

namespace App\Delivery;

use Carbon\CarbonImmutable;

/**
 * The outcome of a successful provider delivery.
 */
final class ProviderResult
{
    /**
     * @param  array<string, mixed>  $raw  decoded provider response body
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly int $statusCode,
        public readonly int $latencyMs,
        public readonly CarbonImmutable $timestamp,
        public readonly array $raw,
    ) {}
}
