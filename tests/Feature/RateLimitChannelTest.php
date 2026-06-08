<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Jobs\Middleware\RateLimitChannel;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Support\RateLimiting\ChannelRateLimiter;

it('passes the job through when under the per-second limit', function () {
    $this->app->instance(ChannelRateLimiter::class, new class extends ChannelRateLimiter
    {
        public function attempt(string $key, int $perSecond): bool
        {
            return true;
        }
    });

    $job = new SendNotification(Notification::factory()->forChannel(Channel::Sms)->make());

    $reached = false;
    (new RateLimitChannel(Channel::Sms))->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeTrue();
});

it('throttles (releases) the job when the per-second limit is exceeded', function () {
    $this->app->instance(ChannelRateLimiter::class, new class extends ChannelRateLimiter
    {
        public function attempt(string $key, int $perSecond): bool
        {
            return false;
        }
    });

    $job = new SendNotification(Notification::factory()->forChannel(Channel::Sms)->make());

    $reached = false;
    (new RateLimitChannel(Channel::Sms))->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeFalse();
});
