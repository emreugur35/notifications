<?php

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the base TestCase to the Feature and Unit suites so Pest tests have
| access to the full application (HTTP kernel, database, etc.).
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Whether a real Redis server is reachable. Pipeline tests that exercise the
 * token-bucket limiter and SETNX idempotency lock are skipped when it is not
 * (e.g. running the suite on the host); they run in the container.
 */
function redisAvailable(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}
