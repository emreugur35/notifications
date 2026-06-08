<?php

declare(strict_types=1);

namespace Domain\Notifications\Jobs;

use Domain\Notifications\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single notification. Processed by the Horizon worker.
 *
 * This is a stub — implement channel-specific delivery in handle().
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    public function handle(): void
    {
        // TODO: deliver $this->notification through its channel.
    }
}
