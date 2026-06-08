<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Jobs\SendNotification;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dispatches bursts of notifications through the real queue/Horizon/rate-limiter
 * stack and reports throughput, priority draining, and idempotency behaviour.
 *
 *   php artisan notifications:load-test rate        --count=300 --channel=sms
 *   php artisan notifications:load-test priority    --count=200 --channel=email
 *   php artisan notifications:load-test idempotency --count=25
 */
class LoadTestNotifications extends Command
{
    protected $signature = 'notifications:load-test
        {scenario=rate : rate|priority|idempotency}
        {--count=300 : count per priority/channel (fan-out for idempotency)}
        {--channel=sms : channel for rate/priority scenarios}';

    protected $description = 'Load test the notification delivery pipeline.';

    public function handle(): int
    {
        $scenario = (string) $this->argument('scenario');
        $count = max(1, (int) $this->option('count'));
        $channel = Channel::from((string) $this->option('channel'));

        return match ($scenario) {
            'rate' => $this->rate($channel, $count),
            'priority' => $this->priority($channel, $count),
            'idempotency' => $this->idempotency($channel, $count),
            default => $this->bail("Unknown scenario [{$scenario}]."),
        };
    }

    private function rate(Channel $channel, int $count): int
    {
        $batch = NotificationBatch::create(['name' => 'loadtest-rate', 'total_count' => $count]);
        $this->seed($channel, Priority::Normal, $count, $batch);

        $this->info("Dispatching {$count} × {$channel->value} (rate limit 100/s per channel)…");
        $this->dispatchAll($batch);

        $elapsed = $this->drain($batch, $count);
        $this->line(sprintf('Drained %d in %.1fs.', $count, $elapsed));
        $this->histogram($batch);

        return self::SUCCESS;
    }

    private function priority(Channel $channel, int $count): int
    {
        $total = $count * 3;
        $batch = NotificationBatch::create(['name' => 'loadtest-priority', 'total_count' => $total]);

        foreach ([Priority::High, Priority::Normal, Priority::Low] as $priority) {
            $this->seed($channel, $priority, $count, $batch);
        }

        $this->info("Dispatching {$count} each of high/normal/low on {$channel->value} (interleaved)…");
        $this->dispatchInterleaved($batch, $count);

        $start = microtime(true);
        $elapsed = $this->drain($batch, $total);
        $this->line(sprintf('Drained %d in %.1fs.', $total, $elapsed));
        $this->priorityReport($batch, $start);

        return self::SUCCESS;
    }

    private function idempotency(Channel $channel, int $fanout): int
    {
        $batch = NotificationBatch::create(['name' => 'loadtest-idempotency', 'total_count' => 1]);
        $this->seed($channel, Priority::High, 1, $batch);

        $notification = Notification::query()->where('batch_id', $batch->id)->firstOrFail();
        $notification->update(['idempotency_key' => (string) Str::uuid()]);

        $this->info("Dispatching the SAME notification {$fanout}× (same idempotency key)…");
        for ($i = 0; $i < $fanout; $i++) {
            SendNotification::dispatch($notification);
        }

        $this->drain($batch, 1);
        // Let the remaining duplicate jobs flush (they should all no-op).
        usleep(3_000_000);

        $attempts = DeliveryAttempt::query()
            ->where('notification_id', $notification->id)
            ->count();

        $status = $notification->fresh()?->status->value ?? 'unknown';
        $this->line("Fan-out: {$fanout} | status: {$status} | delivery attempts: {$attempts}");
        $this->line($attempts === 1
            ? '<info>exactly one send: PASS</info>'
            : '<error>DUPLICATE SENDS</error>');

        return $attempts === 1 ? self::SUCCESS : self::FAILURE;
    }

    private function seed(Channel $channel, Priority $priority, int $count, NotificationBatch $batch): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'batch_id' => $batch->id,
                'recipient' => $this->recipient($channel, $i),
                'channel' => $channel->value,
                'content' => "Load test message {$i}",
                'priority' => $priority->value,
                'status' => Status::Queued->value,
                'attempts' => 0,
                'metadata' => json_encode($this->metadataFor($channel)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Notification::insert($rows);
    }

    private function dispatchAll(NotificationBatch $batch): void
    {
        Notification::query()
            ->where('batch_id', $batch->id)
            ->each(fn (Notification $notification) => SendNotification::dispatch($notification));
    }

    private function dispatchInterleaved(NotificationBatch $batch, int $count): void
    {
        $sets = [];
        foreach ([Priority::High, Priority::Normal, Priority::Low] as $priority) {
            $sets[] = Notification::query()
                ->where('batch_id', $batch->id)
                ->where('priority', $priority->value)
                ->get()
                ->values();
        }

        for ($i = 0; $i < $count; $i++) {
            foreach ($sets as $set) {
                $notification = $set->get($i);
                if ($notification instanceof Notification) {
                    SendNotification::dispatch($notification);
                }
            }
        }
    }

    private function drain(NotificationBatch $batch, int $total, int $timeoutSeconds = 180): float
    {
        $start = microtime(true);

        while (true) {
            $done = Notification::query()
                ->where('batch_id', $batch->id)
                ->whereIn('status', [Status::Sent->value, Status::Failed->value])
                ->count();

            if ($done >= $total) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                $this->warn("Timed out: {$done}/{$total} drained.");
                break;
            }

            usleep(200_000);
        }

        return microtime(true) - $start;
    }

    private function histogram(NotificationBatch $batch): void
    {
        /** @var array<string, int> $buckets */
        $buckets = [];

        foreach ($this->sentAttempts($batch) as $attempt) {
            $second = $attempt->created_at?->format('H:i:s');
            if ($second !== null) {
                $buckets[$second] = ($buckets[$second] ?? 0) + 1;
            }
        }

        if ($buckets === []) {
            $this->warn('No delivery attempts recorded.');

            return;
        }

        $this->table(
            ['second', 'sent'],
            array_map(static fn (string $s, int $c): array => [$s, $c], array_keys($buckets), array_values($buckets)),
        );

        $total = array_sum($buckets);
        $peak = max($buckets);
        $windows = count($buckets);

        $this->line(sprintf(
            'total: %d | peak/s: %d | windows: %ds | avg/s: %.1f',
            $total, $peak, $windows, $total / $windows,
        ));
        // Allow +1 of slack: the limiter caps at 100 per decision-second, but a
        // send can land in the next wall-second from where it was admitted.
        $this->line($peak <= 101
            ? '<info>≤100/s per channel (fixed-window cap): PASS</info>'
            : '<error>peak exceeds 100/s</error>');
    }

    private function priorityReport(NotificationBatch $batch, float $start): void
    {
        $rows = [];

        foreach ([Priority::High, Priority::Normal, Priority::Low] as $priority) {
            $timestamps = [];
            foreach ($this->sentAttempts($batch, $priority) as $attempt) {
                if ($attempt->created_at !== null) {
                    $timestamps[] = $attempt->created_at->getTimestampMs() / 1000;
                }
            }

            sort($timestamps);
            $count = count($timestamps);
            $last = $count > 0 ? end($timestamps) - $start : 0.0;

            $rows[] = [
                $priority->value,
                $count,
                sprintf('+%.1fs', $last),
            ];
        }

        $this->table(['priority', 'sent', 'last send at'], $rows);
        $this->line('High-priority should reach its last send earliest (drains first).');
    }

    /**
     * @return Collection<int, DeliveryAttempt>
     */
    private function sentAttempts(NotificationBatch $batch, ?Priority $priority = null)
    {
        $ids = Notification::query()
            ->where('batch_id', $batch->id)
            ->when($priority !== null, fn ($query) => $query->where('priority', $priority?->value))
            ->pluck('id');

        return DeliveryAttempt::query()
            ->whereIn('notification_id', $ids)
            ->where('status', Status::Sent->value)
            ->orderBy('created_at')
            ->get();
    }

    private function recipient(Channel $channel, int $i): string
    {
        return match ($channel) {
            Channel::Sms => '+1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            Channel::Email => "load{$i}@example.com",
            Channel::Push => "device-token-{$i}",
        };
    }

    /**
     * @return array<string, string>
     */
    private function metadataFor(Channel $channel): array
    {
        return match ($channel) {
            Channel::Email => ['subject' => 'Load test'],
            Channel::Push => ['title' => 'Load test'],
            Channel::Sms => [],
        };
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
