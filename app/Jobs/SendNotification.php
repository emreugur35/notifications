<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Status;
use App\Jobs\Middleware\RateLimitChannel;
use App\Models\Notification;
use App\Services\NotificationSender;
use App\Support\Content\ContentValidationException;
use App\Support\Content\ContentValidator;
use App\Support\Idempotency\IdempotencyLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single notification. Dispatched onto the queue matching the
 * notification's priority and processed by the matching Horizon supervisor.
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly Notification $notification,
    ) {
        $this->onQueue($notification->priority->queue());
    }

    /**
     * Per-channel token-bucket throttling (100 msg/s per channel).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimitChannel($this->notification->channel)];
    }

    public function handle(
        ContentValidator $validator,
        IdempotencyLock $lock,
        NotificationSender $sender,
    ): void {
        $notification = $this->notification->fresh();

        // Already handled (sent/delivered/failed/cancelled) or in flight.
        if ($notification === null
            || $notification->status->isTerminal()
            || $notification->status === Status::Processing
        ) {
            return;
        }

        // SETNX lock keyed by idempotency key (falling back to the id) so that
        // concurrent dispatches of the same notification send exactly once.
        $lockKey = $notification->idempotency_key ?? $notification->id;

        if (! $lock->acquire($lockKey)) {
            return;
        }

        $metadata = $notification->metadata ?? [];

        try {
            $validator->validate($notification->channel, [
                'content' => $notification->content,
                'subject' => $metadata['subject'] ?? null,
                'title' => $metadata['title'] ?? null,
            ]);

            $notification->update(['status' => Status::Processing]);
            $sender->send($notification);
            // Lock is intentionally held for its TTL after a successful send.
        } catch (ContentValidationException $e) {
            $notification->forceFill([
                'status' => Status::Failed,
                'attempts' => $notification->attempts + 1,
            ])->save();

            $notification->deliveryAttempts()->create([
                'attempt_number' => $notification->attempts,
                'status' => Status::Failed,
                'error' => $e->getMessage(),
            ]);

            $lock->release($lockKey);
        }
    }
}
