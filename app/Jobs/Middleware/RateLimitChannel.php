<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\Channel;
use App\Jobs\SendNotification;
use App\Support\RateLimiting\ChannelRateLimiter;
use Closure;

/**
 * Job middleware that caps delivery at a per-channel rate (default 100
 * messages/second per channel). When the second's budget is exhausted the job
 * is released back onto its queue to be retried in the next window.
 */
class RateLimitChannel
{
    public function __construct(
        private readonly Channel $channel,
        private readonly int $ratePerSecond = 100,
    ) {}

    public function handle(SendNotification $job, Closure $next): mixed
    {
        $allowed = app(ChannelRateLimiter::class)
            ->attempt('channel:'.$this->channel->value, $this->ratePerSecond);

        if ($allowed) {
            return $next($job);
        }

        $job->release(1);

        return null;
    }
}
