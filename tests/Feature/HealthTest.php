<?php

declare(strict_types=1);

use App\Support\Health\HealthChecker;

it('returns 200 when all dependencies are healthy (stubbed)', function () {
    $this->app->instance(HealthChecker::class, new class extends HealthChecker
    {
        public function run(): array
        {
            return [
                'database' => ['status' => 'ok', 'latency_ms' => 0.5],
                'redis' => ['status' => 'ok', 'latency_ms' => 0.3],
                'queue' => ['status' => 'ok', 'latency_ms' => 0.4],
            ];
        }
    });

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'notifications')
        ->assertJsonPath('checks.database.status', 'ok');
});

it('degrades to 503 when a dependency is down', function () {
    $this->app->instance(HealthChecker::class, new class extends HealthChecker
    {
        public function run(): array
        {
            return [
                'database' => ['status' => 'ok', 'latency_ms' => 0.5],
                'redis' => ['status' => 'error', 'latency_ms' => 2001.0, 'error' => 'Connection refused'],
                'queue' => ['status' => 'ok', 'latency_ms' => 0.4],
            ];
        }
    });

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis.status', 'error');
});

it('probes real dependencies and reports ok', function () {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis is required to verify the real health probes.');
    }

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertHeader('X-Correlation-Id');
});
