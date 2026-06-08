<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\CircuitBreaker;
use App\Delivery\Exceptions\PermanentProviderException;
use App\Delivery\Exceptions\TransientProviderException;
use App\Delivery\NotificationProvider;
use App\Enums\Status;
use App\Jobs\Middleware\EnsureChannelCircuitClosed;
use App\Jobs\Middleware\RateLimitChannel;
use App\Models\Notification;
use App\Support\Content\ContentValidationException;
use App\Support\Content\ContentValidator;
use App\Support\Idempotency\IdempotencyLock;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Delivers a single notification through the configured provider. Dispatched
 * onto the queue matching the notification's priority and processed by the
 * matching Horizon supervisor.
 *
 * Retry budget is governed by maxExceptions + retryUntil rather than tries, so
 * circuit-breaker / rate-limit pauses (which release without throwing) don't
 * consume the budget.
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Unlimited attempts; bounded instead by maxExceptions + retryUntil(). */
    public int $tries = 0;

    /** At most 5 thrown exceptions before the job is failed. */
    public int $maxExceptions = 5;

    public function __construct(
        public readonly Notification $notification,
    ) {
        $this->onQueue($notification->priority->queue());
    }

    /**
     * Stop retrying 30 minutes after the job was first dispatched.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * Backoff schedule (seconds) with up to +20% jitter to avoid thundering herds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(
            fn (int $seconds): int => $seconds + random_int(0, (int) round($seconds * 0.2)),
            [10, 30, 60, 120, 300],
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new EnsureChannelCircuitClosed,
            new RateLimitChannel($this->notification->channel),
        ];
    }

    public function handle(
        NotificationProvider $provider,
        CircuitBreaker $breaker,
        ContentValidator $validator,
        IdempotencyLock $lock,
    ): void {
        $notification = $this->notification->fresh();

        if ($notification === null || $this->isTerminal($notification)) {
            return;
        }

        // Serialize concurrent dispatches of the same notification.
        $lockKey = $notification->idempotency_key ?? $notification->id;
        if (! $lock->acquire($lockKey)) {
            return;
        }

        $attempt = $notification->attempts + 1;
        $metadata = $notification->metadata ?? [];

        // Per-message content validation — permanent, never tripping the breaker.
        try {
            $validator->validate($notification->channel, [
                'content' => $notification->content,
                'subject' => $metadata['subject'] ?? null,
                'title' => $metadata['title'] ?? null,
            ]);
        } catch (ContentValidationException $e) {
            $this->recordAttempt($notification, $attempt, Status::Failed, null, $e->getMessage(), 0);
            $lock->release($lockKey);
            $this->fail($e);

            return;
        }

        $notification->update(['status' => Status::Processing]);

        // Delivery — the provider owns success/failure classification.
        try {
            $result = $provider->send($notification);
        } catch (TransientProviderException $e) {
            $this->recordAttempt($notification, $attempt, Status::Failed, $this->httpCode($e->statusCode), $e->getMessage(), $e->latencyMs);
            $breaker->recordFailure($notification->channel);
            $lock->release($lockKey);

            throw $e; // retry with backoff
        } catch (PermanentProviderException $e) {
            $this->recordAttempt($notification, $attempt, Status::Failed, $this->httpCode($e->statusCode), $e->getMessage(), $e->latencyMs);
            $lock->release($lockKey);
            $this->fail($e); // straight to failed_jobs

            return;
        }

        // Success.
        $breaker->recordSuccess($notification->channel);
        $notification->forceFill([
            'status' => Status::Sent,
            'provider_message_id' => $result->messageId,
            'attempts' => $attempt,
        ])->save();
        $this->recordAttempt($notification, $attempt, Status::Sent, $result->statusCode, null, $result->latencyMs);
        // Lock held for its TTL to dedupe lingering duplicate dispatches.
    }

    /**
     * Mark the notification failed once the job is ultimately exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $notification = $this->notification->fresh();

        if ($notification !== null && ! $this->isTerminal($notification)) {
            $notification->update(['status' => Status::Failed]);
        }
    }

    private function isTerminal(Notification $notification): bool
    {
        return in_array(
            $notification->status,
            [Status::Sent, Status::Delivered, Status::Cancelled],
            true,
        );
    }

    private function recordAttempt(
        Notification $notification,
        int $attempt,
        Status $status,
        ?int $responseCode,
        ?string $error,
        int $latencyMs,
    ): void {
        $notification->deliveryAttempts()->create([
            'attempt_number' => $attempt,
            'status' => $status,
            'response_code' => $responseCode,
            'error' => $error,
            'latency_ms' => $latencyMs,
        ]);

        if ($status !== Status::Sent) {
            // Success already persisted attempts alongside the status change.
            $notification->forceFill(['attempts' => $attempt])->save();
        }
    }

    private function httpCode(int $statusCode): ?int
    {
        return $statusCode > 0 ? $statusCode : null;
    }
}
