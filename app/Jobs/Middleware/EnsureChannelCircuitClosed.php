<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Delivery\CircuitBreaker;
use App\Jobs\SendNotification;
use Closure;

/**
 * Job middleware that short-circuits delivery when the notification's channel
 * circuit is open. It releases the job back onto its queue (without throwing,
 * so the retry budget is untouched) for the remaining cooldown — keeping an
 * outage on one channel from stalling delivery on the others.
 */
class EnsureChannelCircuitClosed
{
    public function handle(SendNotification $job, Closure $next): mixed
    {
        $breaker = app(CircuitBreaker::class);
        $channel = $job->notification->channel;

        if ($breaker->isOpen($channel)) {
            $job->release($breaker->cooldownRemaining($channel));

            return null;
        }

        return $next($job);
    }
}
