<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Note: the transient-error retry signal (re-throw + backoff) and the
// permanent-error fail-fast are covered in DeliveryTest. These cover the
// terminal failed() handler — i.e. the "→ failed" end of the retry path.

it('transitions to failed when the job is ultimately exhausted', function () {
    $notification = Notification::factory()
        ->forChannel(Channel::Sms)
        ->withStatus(Status::Processing)
        ->create();

    (new SendNotification($notification))->failed(new RuntimeException('retries exhausted'));

    expect($notification->fresh()?->status)->toBe(Status::Failed);
});

it('does not overwrite a terminal (sent) notification on failure', function () {
    $notification = Notification::factory()
        ->forChannel(Channel::Sms)
        ->withStatus(Status::Sent)
        ->create();

    (new SendNotification($notification))->failed(new RuntimeException('late failure'));

    expect($notification->fresh()?->status)->toBe(Status::Sent);
});

it('declares the maxExceptions + retryUntil budget (not tries)', function () {
    $job = new SendNotification(Notification::factory()->forChannel(Channel::Sms)->make());

    expect($job->tries)->toBe(0)                  // unlimited attempts
        ->and($job->maxExceptions)->toBe(5)       // bounded by thrown exceptions
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});
