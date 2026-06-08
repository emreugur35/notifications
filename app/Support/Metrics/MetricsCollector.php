<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\DeliveryAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Aggregates operational metrics: queue depth per priority, success/failure
 * counts over a rolling window, throughput, and delivery latency percentiles.
 */
class MetricsCollector
{
    public function __construct(private readonly int $windowMinutes = 5) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $since = Carbon::now()->subMinutes($this->windowMinutes);

        return [
            'window_minutes' => $this->windowMinutes,
            'queue_depth' => $this->queueDepth(),
            'counts' => $this->counts($since),
            'throughput_per_min' => $this->throughputPerMinute($since),
            'latency_ms' => $this->latencyPercentiles($since),
        ];
    }

    /**
     * Pending jobs waiting on each priority queue.
     *
     * @return array<string, int>
     */
    private function queueDepth(): array
    {
        $connection = Queue::connection();
        $depth = [];

        foreach (Priority::cases() as $priority) {
            try {
                $depth[$priority->value] = $connection->size($priority->queue());
            } catch (Throwable) {
                $depth[$priority->value] = 0;
            }
        }

        return $depth;
    }

    /**
     * Successful vs failed delivery attempts within the window.
     *
     * @return array{sent: int, failed: int}
     */
    private function counts(Carbon $since): array
    {
        $counts = DeliveryAttempt::query()
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        return [
            'sent' => (int) $counts->get(Status::Sent->value, 0),
            'failed' => (int) $counts->get(Status::Failed->value, 0),
        ];
    }

    private function throughputPerMinute(Carbon $since): float
    {
        $sent = DeliveryAttempt::query()
            ->where('status', Status::Sent->value)
            ->where('created_at', '>=', $since)
            ->count();

        return round($sent / max(1, $this->windowMinutes), 2);
    }

    /**
     * p50/p95/p99 of successful-delivery latency within the window.
     *
     * @return array{p50: int|null, p95: int|null, p99: int|null}
     */
    private function latencyPercentiles(Carbon $since): array
    {
        $latencies = array_values(
            DeliveryAttempt::query()
                ->where('status', Status::Sent->value)
                ->where('created_at', '>=', $since)
                ->whereNotNull('latency_ms')
                ->orderBy('latency_ms')
                ->pluck('latency_ms')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all()
        );

        return [
            'p50' => $this->percentile($latencies, 0.50),
            'p95' => $this->percentile($latencies, 0.95),
            'p99' => $this->percentile($latencies, 0.99),
        ];
    }

    /**
     * Nearest-rank percentile over an ascending list.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $quantile): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $rank = (int) ceil($quantile * $count) - 1;
        $rank = max(0, min($rank, $count - 1));

        return $sorted[$rank];
    }
}
