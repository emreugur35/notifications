<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Status;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Requests\IndexNotificationRequest;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group Notifications
 *
 * Create, inspect, list, and cancel notifications.
 */
class NotificationController
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * List notifications
     *
     * Returns a paginated list of notifications, optionally filtered by status,
     * channel, and creation date range.
     */
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 15);

        $notifications = Notification::query()
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('channel'), fn ($query, $channel) => $query->where('channel', $channel))
            ->when($request->date('from'), fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($request->date('to'), fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['correlation_id' => AssignCorrelationId::fromRequest($request)]);
    }

    /**
     * Create a notification
     *
     * Creates a single notification. Supplying an idempotency key makes repeat
     * requests return the original notification instead of creating a duplicate.
     *
     * @response 201 scenario="Created" {"data": {"status": "pending"}, "correlation_id": "..."}
     */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $notification = $this->service->create($request->validated());

        return NotificationResource::make($notification)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Create a batch of notifications
     *
     * Creates up to 1000 notifications under a single batch. Requests with more
     * than 1000 notifications are rejected with a 422.
     *
     * @response 201 scenario="Created" {"data": {"total_count": 3}, "rollup": {"pending": 3}, "correlation_id": "..."}
     */
    public function storeBatch(StoreNotificationBatchRequest $request): JsonResponse
    {
        /** @var array{name?: string|null, notifications: array<int, array<string, mixed>>} $data */
        $data = $request->validated();

        $batch = $this->service->createBatch($data);

        return NotificationBatchResource::make($batch)
            ->additional(['rollup' => $this->service->rollup($batch)])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Get a notification
     *
     * Returns a notification's current status and its delivery attempts.
     *
     * @urlParam notification string required The notification UUID. Example: 9b1d...
     */
    public function show(Notification $notification): NotificationResource
    {
        $notification->load('deliveryAttempts');

        return NotificationResource::make($notification);
    }

    /**
     * Cancel a notification
     *
     * Cancels a notification that has not yet been sent. Only notifications in
     * the pending or queued state may be cancelled; otherwise a 409 is returned.
     *
     * @urlParam notification string required The notification UUID. Example: 9b1d...
     *
     * @response 409 scenario="Not cancellable" {"message": "Notification cannot be cancelled in its current state."}
     */
    public function cancel(Notification $notification): NotificationResource
    {
        abort_unless(
            in_array($notification->status, [Status::Pending, Status::Queued], true),
            Response::HTTP_CONFLICT,
            'Notification cannot be cancelled in its current state.'
        );

        return NotificationResource::make($this->service->cancel($notification));
    }
}
