<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use Illuminate\Support\Facades\Redis;

/**
 * Redis SETNX-based lock used to guarantee a notification is only sent once,
 * even when the same notification is dispatched concurrently. The lock is held
 * for its TTL after a successful send so racing dispatches are deduplicated.
 */
class IdempotencyLock
{
    public function acquire(string $key, int $ttlSeconds = 3600): bool
    {
        // SET key 1 EX <ttl> NX — atomic acquire; returns "OK" or null.
        $result = Redis::connection()->command('set', [
            $this->key($key), '1', 'EX', $ttlSeconds, 'NX',
        ]);

        return $result !== null;
    }

    public function release(string $key): void
    {
        Redis::connection()->command('del', [$this->key($key)]);
    }

    private function key(string $key): string
    {
        return 'idempotency:lock:'.$key;
    }
}
