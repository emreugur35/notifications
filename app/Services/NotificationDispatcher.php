<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Channel;

/**
 * Entry point for dispatching notifications onto the appropriate queue.
 *
 * This is a stub — wire concrete delivery logic and persistence here.
 */
class NotificationDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(Channel $channel, array $payload): void
    {
        // TODO: persist a Notification record and push the matching Job
        //       onto $channel->queue().
    }
}
