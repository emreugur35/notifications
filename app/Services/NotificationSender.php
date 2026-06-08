<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * Hands a notification off to its delivery provider and records the attempt.
 *
 * This is a simulated sender — swap in real per-channel drivers (SMS gateway,
 * SMTP/ESP, push provider) behind this seam.
 */
class NotificationSender
{
    public function send(Notification $notification): void
    {
        $startedAt = microtime(true);

        // Simulated provider hand-off.
        $providerMessageId = (string) Str::uuid();

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $attemptNumber = $notification->attempts + 1;

        $notification->forceFill([
            'status' => Status::Sent,
            'provider_message_id' => $providerMessageId,
            'attempts' => $attemptNumber,
        ])->save();

        $notification->deliveryAttempts()->create([
            'attempt_number' => $attemptNumber,
            'status' => Status::Sent,
            'response_code' => 200,
            'latency_ms' => $latencyMs,
        ]);
    }
}
