<?php

declare(strict_types=1);

use Domain\Notifications\Enums\Channel;
use Domain\Notifications\Enums\Status;
use Domain\Notifications\Models\DeliveryAttempt;
use Domain\Notifications\Models\Notification;
use Domain\Notifications\Models\NotificationBatch;
use Domain\Notifications\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists notifications with enum casts and uuid keys', function () {
    $notification = Notification::factory()->forChannel(Channel::Email)->create();

    expect($notification->id)->toBeString()
        ->and($notification->channel)->toBe(Channel::Email)
        ->and($notification->status)->toBe(Status::Pending)
        ->and($notification->metadata)->toBeArray();
});

it('wires batch -> notifications -> delivery attempts relationships', function () {
    $batch = NotificationBatch::factory()->create();

    $notification = Notification::factory()->forBatch($batch)->create();
    DeliveryAttempt::factory()->count(2)->create(['notification_id' => $notification->id]);

    expect($batch->notifications)->toHaveCount(1)
        ->and($notification->batch->id)->toBe($batch->id)
        ->and($notification->deliveryAttempts)->toHaveCount(2)
        ->and($notification->deliveryAttempts->first()->notification->id)->toBe($notification->id);
});

it('creates templates with a unique key and channel cast', function () {
    $template = Template::factory()->create(['key' => 'welcome-email', 'channel' => Channel::Email]);

    expect($template->key)->toBe('welcome-email')
        ->and($template->channel)->toBe(Channel::Email);
});
