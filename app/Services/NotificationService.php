<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Support\Content\ContentValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly ContentValidator $contentValidator) {}

    /**
     * Create a single notification, transition it to queued, and dispatch the
     * delivery job. If an idempotency key is supplied and a notification
     * already exists for it, the existing record is returned without
     * re-dispatching.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Notification
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = Notification::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $channel = $this->channel($data['channel'] ?? null);
        $contentMeta = $this->contentValidator->validate($channel, $data);

        $notification = Notification::create([
            'batch_id' => $data['batch_id'] ?? null,
            'recipient' => $data['recipient'],
            'channel' => $channel,
            'content' => $data['content'],
            'priority' => $data['priority'] ?? 'normal',
            'status' => Status::Pending,
            'idempotency_key' => $idempotencyKey,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'attempts' => 0,
            'metadata' => $this->buildMetadata($data, $contentMeta),
        ]);

        // Due now (no schedule, or already elapsed): queue + dispatch.
        // Future-scheduled: stay pending until the scheduler picks it up.
        if ($this->isDue($notification)) {
            $this->markQueuedAndDispatch($notification);
        } else {
            Log::info('notification.scheduled', [
                'notification_id' => $notification->id,
                'channel' => $channel->value,
                'scheduled_at' => $notification->scheduled_at?->toIso8601String(),
            ]);
        }

        return $notification;
    }

    /**
     * Create a batch and its notifications in a single transaction using a
     * bulk insert (efficient for up to 1000 rows).
     *
     * @param  array{name?: string|null, notifications: array<int, array<string, mixed>>}  $data
     */
    public function createBatch(array $data): NotificationBatch
    {
        $batch = DB::transaction(function () use ($data): NotificationBatch {
            $items = $data['notifications'];
            $count = count($items);

            $batch = NotificationBatch::create([
                'name' => $data['name'] ?? null,
                'total_count' => $count,
                'pending_count' => $count,
            ]);

            $now = now();
            $rows = [];

            foreach ($items as $item) {
                $channel = $this->channel($item['channel'] ?? null);
                $contentMeta = $this->contentValidator->validate($channel, $item);

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batch->id,
                    'recipient' => $item['recipient'],
                    'channel' => $channel->value,
                    'content' => $item['content'],
                    'priority' => $item['priority'] ?? 'normal',
                    'status' => Status::Pending->value,
                    'idempotency_key' => $item['idempotency_key'] ?? null,
                    'scheduled_at' => $item['scheduled_at'] ?? null,
                    'attempts' => 0,
                    'metadata' => json_encode($this->buildMetadata($item, $contentMeta)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Notification::insert($rows);

            return $batch;
        });

        // Dispatch only the notifications that are due now; future-scheduled
        // ones stay pending until the scheduler picks them up (post-commit).
        $dueIds = Notification::query()
            ->where('batch_id', $batch->id)
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->pluck('id');

        Notification::query()->whereIn('id', $dueIds)->update(['status' => Status::Queued->value]);

        Notification::query()->whereIn('id', $dueIds)->each(function (Notification $notification): void {
            SendNotification::dispatch($notification);
        });

        Log::info('notification.batch_queued', [
            'batch_id' => $batch->id,
            'dispatched' => $dueIds->count(),
            'total' => $batch->total_count,
        ]);

        return $batch;
    }

    /**
     * Dispatch pending notifications whose scheduled_at has come due. Invoked
     * every minute by the scheduler (schedule:work).
     */
    public function dispatchDue(): int
    {
        $dispatched = 0;

        Notification::query()
            ->where('status', Status::Pending->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (Notification $notification) use (&$dispatched): void {
                $this->markQueuedAndDispatch($notification);
                $dispatched++;
            });

        return $dispatched;
    }

    /**
     * A notification is due when it has no schedule or its schedule has elapsed.
     */
    private function isDue(Notification $notification): bool
    {
        return $notification->scheduled_at === null
            || ! $notification->scheduled_at->isFuture();
    }

    /**
     * Transition pending -> queued and dispatch the delivery job.
     */
    private function markQueuedAndDispatch(Notification $notification): void
    {
        $notification->update(['status' => Status::Queued]);
        SendNotification::dispatch($notification);

        // Request-side log; the correlation id (from Context) ties this to the
        // worker's later delivery-attempt log.
        Log::info('notification.queued', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
        ]);
    }

    /**
     * Resolve a Channel from a validated value (string or enum).
     */
    private function channel(mixed $value): Channel
    {
        return $value instanceof Channel
            ? $value
            : Channel::from(is_string($value) ? $value : '');
    }

    /**
     * Merge channel content metadata (e.g. SMS segmentation) and the optional
     * subject/title fields into the stored metadata payload.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $contentMeta
     * @return array<string, mixed>
     */
    private function buildMetadata(array $data, array $contentMeta): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        foreach (['subject', 'title'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $metadata[$key] = $value;
            }
        }

        return array_merge($metadata, $contentMeta);
    }

    /**
     * Transition a notification to the cancelled state.
     */
    public function cancel(Notification $notification): Notification
    {
        $notification->update(['status' => Status::Cancelled]);

        return $notification;
    }

    /**
     * Live per-status rollup of a batch's notifications, with every status
     * present (zero-filled).
     *
     * @return array<string, int>
     */
    public function rollup(NotificationBatch $batch): array
    {
        /** @var Collection<string, int> $counts */
        $counts = Notification::query()
            ->where('batch_id', $batch->id)
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        $rollup = [];

        foreach (Status::cases() as $status) {
            $rollup[$status->value] = (int) $counts->get($status->value, 0);
        }

        return $rollup;
    }
}
