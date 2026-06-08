<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Support\Idempotency\IdempotencyLock;
use App\Support\RateLimiting\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis is required for the delivery pipeline tests.');
    }
});

it('sends a notification exactly once even when dispatched twice', function () {
    $notification = Notification::factory()
        ->forChannel(Channel::Sms)
        ->withStatus(Status::Queued)
        ->create(['content' => 'Hi there', 'idempotency_key' => (string) Str::uuid()]);

    SendNotification::dispatchSync($notification);
    SendNotification::dispatchSync($notification);

    $notification->refresh();

    expect($notification->status)->toBe(Status::Sent)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->provider_message_id)->not->toBeNull()
        ->and($notification->deliveryAttempts()->count())->toBe(1);
});

it('fails a notification whose content is invalid for its channel', function () {
    // Email with no subject in metadata fails channel content validation.
    $notification = Notification::factory()
        ->forChannel(Channel::Email)
        ->withStatus(Status::Queued)
        ->create(['content' => 'Body', 'metadata' => []]);

    SendNotification::dispatchSync($notification);

    $notification->refresh();

    expect($notification->status)->toBe(Status::Failed)
        ->and($notification->deliveryAttempts()->where('status', Status::Failed->value)->count())->toBe(1);
});

it('acquires and releases a SETNX idempotency lock', function () {
    $lock = app(IdempotencyLock::class);
    $key = (string) Str::uuid();

    expect($lock->acquire($key))->toBeTrue()
        ->and($lock->acquire($key))->toBeFalse();

    $lock->release($key);

    expect($lock->acquire($key))->toBeTrue();

    $lock->release($key);
});

it('caps attempts at the per-second limit', function () {
    $limiter = app(ChannelRateLimiter::class);
    $key = 'test:'.Str::uuid();

    expect($limiter->attempt($key, 2))->toBeTrue()
        ->and($limiter->attempt($key, 2))->toBeTrue()
        ->and($limiter->attempt($key, 2))->toBeFalse();
});
