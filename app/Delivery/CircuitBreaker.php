<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

/**
 * Per-channel circuit breaker backed by Redis.
 *
 * A rolling consecutive-failure counter is incremented on each failure (its TTL
 * is refreshed so isolated, spaced-out failures decay). When the counter
 * reaches the threshold the circuit opens for a cooldown window; any success
 * closes it immediately (counter + open flag cleared).
 */
class CircuitBreaker
{
    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 60,
        private readonly int $failureTtlSeconds = 120,
    ) {}

    public function isOpen(Channel $channel): bool
    {
        return (int) Redis::connection()->command('exists', [$this->openKey($channel)]) === 1;
    }

    /**
     * Record a failure; opens the circuit when the threshold is reached.
     */
    public function recordFailure(Channel $channel): void
    {
        $key = $this->failuresKey($channel);

        $failures = (int) Redis::connection()->command('incr', [$key]);
        // Rolling window: refresh the TTL on every failure.
        Redis::connection()->command('expire', [$key, $this->failureTtlSeconds]);

        if ($failures >= $this->threshold) {
            Redis::connection()->command('set', [
                $this->openKey($channel), '1', 'EX', $this->cooldownSeconds,
            ]);
        }
    }

    /**
     * Any success closes the circuit and resets the failure counter.
     */
    public function recordSuccess(Channel $channel): void
    {
        Redis::connection()->command('del', [
            $this->failuresKey($channel),
            $this->openKey($channel),
        ]);
    }

    /**
     * Seconds remaining before the circuit may close (for release delay).
     */
    public function cooldownRemaining(Channel $channel): int
    {
        $ttl = (int) Redis::connection()->command('ttl', [$this->openKey($channel)]);

        return $ttl > 0 ? $ttl : $this->cooldownSeconds;
    }

    private function failuresKey(Channel $channel): string
    {
        return "circuit:{$channel->value}:failures";
    }

    private function openKey(Channel $channel): string
    {
        return "circuit:{$channel->value}:open";
    }
}
