<?php

declare(strict_types=1);

namespace App\Support\RateLimiting;

use Illuminate\Support\Facades\Redis;

/**
 * Atomic Redis fixed-window rate limiter: at most $perSecond allowed attempts
 * per key per wall-clock second. The check-and-increment runs in a single Lua
 * script so it is correct across concurrent workers.
 *
 * A fixed window (rather than a token bucket) is used deliberately: queued jobs
 * are released back at one-second granularity, and a per-second window enforces
 * a hard "no more than N per second" ceiling that aligns with that cadence —
 * whereas a token bucket's burst allowance can briefly exceed it.
 */
class ChannelRateLimiter
{
    private const SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local limit = tonumber(ARGV[1])
        local ttl = tonumber(ARGV[2])

        local current = redis.call('incr', key)
        if current == 1 then
            redis.call('expire', key, ttl)
        end

        if current <= limit then return 1 else return 0 end
    LUA;

    /**
     * Attempt to consume one slot for $key in the current second. Returns true
     * when within the limit, false when the per-second ceiling is reached.
     */
    public function attempt(string $key, int $perSecond): bool
    {
        $window = (int) floor(microtime(true));

        $result = Redis::connection()->command('eval', [
            self::SCRIPT,
            1, // number of KEYS
            "throttle:{$key}:{$window}",
            $perSecond,
            2, // key TTL (seconds)
        ]);

        return (int) $result === 1;
    }
}
