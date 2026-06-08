<?php

declare(strict_types=1);

namespace App\Support\Retry;

/**
 * Computes the retry backoff schedule for failed deliveries: a fixed escalating
 * sequence (seconds) with up to +20% jitter per step to avoid synchronized
 * retry storms across many failed jobs.
 */
class Backoff
{
    /** @var list<int> */
    public const SCHEDULE = [10, 30, 60, 120, 300];

    public const JITTER = 0.2;

    /**
     * The backoff delays (seconds), each with independent 0..+20% jitter.
     *
     * @return list<int>
     */
    public function schedule(): array
    {
        return array_map(
            fn (int $seconds): int => $seconds + random_int(0, (int) round($seconds * self::JITTER)),
            self::SCHEDULE,
        );
    }
}
