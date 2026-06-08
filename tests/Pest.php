<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the base TestCase to the Feature and Unit suites so Pest tests have
| access to the full application (HTTP kernel, database, etc.).
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
