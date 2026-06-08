<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\Status;
use App\Http\Middleware\AssignCorrelationId;
use App\Jobs\SendNotification;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates a notification, transitions it to queued, and dispatches onto the priority queue', function () {
    Queue::fake();

    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'subject' => 'Welcome',
        'content' => 'Hello there',
        'priority' => 'high',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonPath('data.priority', 'high')
        ->assertHeader(AssignCorrelationId::HEADER);

    expect($response->json('correlation_id'))->toBeString();
    $this->assertDatabaseCount('notifications', 1);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->queue === 'notifications-high');
});

it('echoes an inbound correlation id', function () {
    Queue::fake();

    $response = $this->withHeader(AssignCorrelationId::HEADER, 'corr-123')
        ->postJson('/api/v1/notifications', [
            'recipient' => '+15551234567',
            'channel' => 'sms',
            'content' => 'Hi',
        ]);

    $response->assertCreated()
        ->assertHeader(AssignCorrelationId::HEADER, 'corr-123')
        ->assertJsonPath('correlation_id', 'corr-123');
});

it('validates required content and the channel enum', function () {
    $this->postJson('/api/v1/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'carrier-pigeon',
    ])->assertStatus(422)->assertJsonValidationErrors(['content', 'channel']);
});

it('rejects an email without a subject', function () {
    $this->postJson('/api/v1/notifications', [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'content' => 'Body only',
    ])->assertStatus(422)->assertJsonValidationErrors(['subject']);
});

it('rejects a push without a title', function () {
    $this->postJson('/api/v1/notifications', [
        'recipient' => 'device-token',
        'channel' => 'push',
        'content' => 'Body only',
    ])->assertStatus(422)->assertJsonValidationErrors(['title']);
});

it('stores sms segmentation in metadata', function () {
    Queue::fake();

    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+15551234567',
        'channel' => 'sms',
        'content' => str_repeat('a', 200), // GSM-7, 153 chars/segment -> 2 segments
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.metadata.sms.encoding', 'GSM-7')
        ->assertJsonPath('data.metadata.sms.segments', 2);
});

it('is idempotent when an idempotency key is reused', function () {
    Queue::fake();

    $payload = [
        'recipient' => 'user@example.com',
        'channel' => 'email',
        'subject' => 'Once',
        'content' => 'Once',
        'idempotency_key' => 'key-1',
    ];

    $first = $this->postJson('/api/v1/notifications', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/notifications', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->assertDatabaseCount('notifications', 1);

    // Only the first create dispatches; the reused key returns the original.
    Queue::assertPushed(SendNotification::class, 1);
});

it('creates a batch, queues it, and returns a rollup', function () {
    Queue::fake();

    $items = collect(range(1, 5))->map(fn (int $i): array => [
        'recipient' => "user{$i}@example.com",
        'channel' => 'email',
        'subject' => "Subject {$i}",
        'content' => "Message {$i}",
    ])->all();

    $response = $this->postJson('/api/v1/notifications/batch', [
        'name' => 'welcome-wave',
        'notifications' => $items,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.total_count', 5)
        ->assertJsonPath('rollup.queued', 5);

    $this->assertDatabaseCount('notifications', 5);
    Queue::assertPushed(SendNotification::class, 5);
});

it('rejects a batch larger than 1000', function () {
    $items = collect(range(1, 1001))->map(fn (int $i): array => [
        'recipient' => "+1555000{$i}",
        'channel' => 'sms',
        'content' => "Message {$i}",
    ])->all();

    $this->postJson('/api/v1/notifications/batch', ['notifications' => $items])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['notifications']);

    $this->assertDatabaseCount('notification_batches', 0);
});

it('shows a notification with its delivery attempts', function () {
    $notification = Notification::factory()->create();
    DeliveryAttempt::factory()->count(2)->create(['notification_id' => $notification->id]);

    $this->getJson("/api/v1/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id)
        ->assertJsonCount(2, 'data.delivery_attempts');
});

it('shows batch status with a live rollup', function () {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->forBatch($batch)->withStatus(Status::Sent)->count(2)->create();
    Notification::factory()->forBatch($batch)->withStatus(Status::Pending)->create();

    $this->getJson("/api/v1/batches/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('rollup.sent', 2)
        ->assertJsonPath('rollup.pending', 1)
        ->assertJsonPath('rollup.failed', 0);
});

it('cancels a pending notification', function () {
    $notification = Notification::factory()->withStatus(Status::Pending)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('refuses to cancel a notification in a non-cancellable state', function (Status $status) {
    $notification = Notification::factory()->withStatus($status)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertStatus(409);

    expect($notification->fresh()?->status)->toBe($status);
})->with([
    'sent' => [Status::Sent],
    'delivered' => [Status::Delivered],
    'failed' => [Status::Failed],
    'cancelled' => [Status::Cancelled],
]);

it('returns a correlation id (header + body) on every endpoint', function () {
    $batch = NotificationBatch::factory()->create();
    $notification = Notification::factory()->forBatch($batch)->withStatus(Status::Pending)->create();

    $cases = [
        ['getJson', '/api/v1/notifications'],
        ['getJson', "/api/v1/notifications/{$notification->id}"],
        ['getJson', "/api/v1/batches/{$batch->id}"],
        ['postJson', "/api/v1/notifications/{$notification->id}/cancel"],
    ];

    foreach ($cases as [$method, $url]) {
        $response = $this->{$method}($url)->assertOk()->assertHeader(AssignCorrelationId::HEADER);

        expect($response->json('correlation_id'))->toBeString()
            ->and($response->headers->get(AssignCorrelationId::HEADER))
            ->toBe($response->json('correlation_id'));
    }
});

it('filters the notification list by status, channel, and date range', function () {
    Notification::factory()->forChannel(Channel::Email)->withStatus(Status::Sent)
        ->create(['created_at' => '2026-03-01']);
    Notification::factory()->forChannel(Channel::Sms)->withStatus(Status::Pending)
        ->create(['created_at' => '2026-03-05']);
    Notification::factory()->forChannel(Channel::Email)->withStatus(Status::Pending)
        ->create(['created_at' => '2026-01-01']);

    $this->getJson('/api/v1/notifications?status=pending&channel=email&from=2026-02-01&to=2026-04-01')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/notifications?status=sent')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.channel', 'email');

    $this->getJson('/api/v1/notifications?per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');
});
