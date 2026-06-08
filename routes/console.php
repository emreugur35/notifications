<?php

use App\Console\Commands\DispatchScheduledNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dispatch due scheduled notifications once a minute. withoutOverlapping keeps
// a slow run from racing the next tick.
Schedule::command(DispatchScheduledNotifications::class)
    ->everyMinute()
    ->withoutOverlapping();
