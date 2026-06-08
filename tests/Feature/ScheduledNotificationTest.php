<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

it('keeps a future-scheduled notification pending and does not dispatch', function () {
    $this->postJson('/api/v1/notifications', [
        'recipient' => '+15551234567',
        'channel' => 'sms',
        'content' => 'see you later',
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    Queue::assertNotPushed(SendNotification::class);
});

it('dispatches immediately when scheduled_at is null or already past', function () {
    $this->postJson('/api/v1/notifications', [
        'recipient' => '+15551234567',
        'channel' => 'sms',
        'content' => 'send now',
        'scheduled_at' => now()->subMinute()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(SendNotification::class, 1);
});

it('dispatches due scheduled notifications when the scheduler command runs', function () {
    $due = Notification::factory()->forChannel(Channel::Sms)->withStatus(Status::Pending)
        ->create(['scheduled_at' => now()->subMinute()]);

    $future = Notification::factory()->forChannel(Channel::Sms)->withStatus(Status::Pending)
        ->create(['scheduled_at' => now()->addHour()]);

    $this->artisan('notifications:dispatch-scheduled')->assertSuccessful();

    expect($due->fresh()?->status)->toBe(Status::Queued)
        ->and($future->fresh()?->status)->toBe(Status::Pending);

    Queue::assertPushed(SendNotification::class, 1);
});

it('dispatches only the due items of a batch, leaving future ones pending', function () {
    $this->postJson('/api/v1/notifications/batch', [
        'notifications' => [
            ['recipient' => '+15551110000', 'channel' => 'sms', 'content' => 'now'],
            ['recipient' => '+15551110001', 'channel' => 'sms', 'content' => 'later', 'scheduled_at' => now()->addHour()->toIso8601String()],
        ],
    ])->assertCreated();

    // Only the immediately-due item is queued.
    Queue::assertPushed(SendNotification::class, 1);

    expect(Notification::query()->where('status', Status::Pending->value)->count())->toBe(1)
        ->and(Notification::query()->where('status', Status::Queued->value)->count())->toBe(1);
});
