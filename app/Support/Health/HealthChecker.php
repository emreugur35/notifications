<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Probes the dependencies the service needs to function: the database, Redis,
 * and the queue backend. Each probe is isolated so one failure is reported
 * without masking the others.
 */
class HealthChecker
{
    /**
     * @return array<string, array{status: string, latency_ms: float, error?: string}>
     */
    public function run(): array
    {
        return [
            'database' => $this->probe(fn () => DB::connection()->select('select 1')),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
            'queue' => $this->probe(fn () => Queue::connection()->size('notifications-high')),
        ];
    }

    /**
     * @param  array<string, array{status: string, latency_ms: float, error?: string}>  $checks
     */
    public function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(): mixed  $probe
     * @return array{status: string, latency_ms: float, error?: string}
     */
    private function probe(callable $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $probe();

            return ['status' => 'ok', 'latency_ms' => $this->elapsedMs($startedAt)];
        } catch (Throwable $e) {
            return ['status' => 'error', 'latency_ms' => $this->elapsedMs($startedAt), 'error' => $e->getMessage()];
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
