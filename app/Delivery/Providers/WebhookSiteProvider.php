<?php

declare(strict_types=1);

namespace App\Delivery\Providers;

use App\Delivery\Exceptions\PermanentProviderException;
use App\Delivery\Exceptions\TransientProviderException;
use App\Delivery\NotificationProvider;
use App\Delivery\ProviderResult;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Delivers notifications by POSTing { to, channel, content } to a webhook.site
 * URL. Owns success/failure classification:
 *   - 2xx with a messageId  -> success (ProviderResult)
 *   - 2xx without messageId  -> PermanentProviderException
 *   - 429 / 5xx / connection -> TransientProviderException (retryable)
 *   - other 4xx              -> PermanentProviderException
 */
class WebhookSiteProvider implements NotificationProvider
{
    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 5.0,
    ) {}

    public function send(Notification $notification): ProviderResult
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientProviderException(
                'Connection error: '.$e->getMessage(),
                statusCode: 0,
                latencyMs: $this->elapsedMs($startedAt),
                previous: $e,
            );
        }

        $latencyMs = $this->elapsedMs($startedAt);
        $statusCode = $response->status();

        if ($response->successful()) {
            return $this->classifySuccess($response, $statusCode, $latencyMs);
        }

        if ($statusCode === 429 || $statusCode >= 500) {
            throw new TransientProviderException(
                "Provider returned transient HTTP {$statusCode}.",
                statusCode: $statusCode,
                latencyMs: $latencyMs,
            );
        }

        throw new PermanentProviderException(
            "Provider returned permanent HTTP {$statusCode}.",
            statusCode: $statusCode,
            latencyMs: $latencyMs,
        );
    }

    private function classifySuccess(Response $response, int $statusCode, int $latencyMs): ProviderResult
    {
        $body = $this->decode($response);
        $messageId = $this->extractMessageId($body);

        if ($messageId === null) {
            throw new PermanentProviderException(
                'Provider returned 2xx without a messageId.',
                statusCode: $statusCode,
                latencyMs: $latencyMs,
            );
        }

        return new ProviderResult(
            messageId: $messageId,
            status: 'sent',
            statusCode: $statusCode,
            latencyMs: $latencyMs,
            timestamp: CarbonImmutable::now(),
            raw: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractMessageId(array $body): ?string
    {
        foreach (['messageId', 'message_id', 'uuid', 'id'] as $key) {
            $value = $body[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Elapsed milliseconds since $startedAt, measured with the monotonic clock.
     */
    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
