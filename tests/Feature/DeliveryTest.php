<?php

declare(strict_types=1);

use App\Delivery\CircuitBreaker;
use App\Delivery\Exceptions\TransientProviderException;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\Middleware\EnsureChannelCircuitClosed;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis is required for the delivery tests.');
    }

    // Start each test with closed circuits.
    $breaker = app(CircuitBreaker::class);
    foreach (Channel::cases() as $channel) {
        $breaker->recordSuccess($channel);
    }
});

// --- CircuitBreaker --------------------------------------------------------

it('opens the circuit at the failure threshold and closes on success', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 4) as $ignored) {
        $breaker->recordFailure(Channel::Sms);
    }
    expect($breaker->isOpen(Channel::Sms))->toBeFalse();

    $breaker->recordFailure(Channel::Sms); // 5th -> threshold
    expect($breaker->isOpen(Channel::Sms))->toBeTrue()
        ->and($breaker->cooldownRemaining(Channel::Sms))->toBeGreaterThan(0);

    $breaker->recordSuccess(Channel::Sms);
    expect($breaker->isOpen(Channel::Sms))->toBeFalse();
});

it('isolates channels — an SMS outage leaves email closed', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 5) as $ignored) {
        $breaker->recordFailure(Channel::Sms);
    }

    expect($breaker->isOpen(Channel::Sms))->toBeTrue()
        ->and($breaker->isOpen(Channel::Email))->toBeFalse();
});

// --- EnsureChannelCircuitClosed middleware --------------------------------

it('releases (skips next) when the channel circuit is open', function () {
    $breaker = app(CircuitBreaker::class);
    foreach (range(1, 5) as $ignored) {
        $breaker->recordFailure(Channel::Sms);
    }

    $job = new SendNotification(Notification::factory()->forChannel(Channel::Sms)->make());

    $reached = false;
    (new EnsureChannelCircuitClosed)->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeFalse();
});

it('passes through when the channel circuit is closed', function () {
    $job = new SendNotification(Notification::factory()->forChannel(Channel::Push)->make());

    $reached = false;
    (new EnsureChannelCircuitClosed)->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeTrue();
});

// --- SendNotification delivery --------------------------------------------

it('delivers successfully: status sent, provider id, and a recorded attempt', function () {
    Http::fake(['*' => Http::response(['messageId' => 'mid-success'], 200)]);

    $notification = Notification::factory()->forChannel(Channel::Sms)
        ->withStatus(Status::Queued)->create(['content' => 'hi']);

    SendNotification::dispatchSync($notification);

    $notification->refresh();

    expect($notification->status)->toBe(Status::Sent)
        ->and($notification->provider_message_id)->toBe('mid-success')
        ->and($notification->attempts)->toBe(1)
        ->and($notification->deliveryAttempts()->where('status', Status::Sent->value)->count())->toBe(1);

    expect(app(CircuitBreaker::class)->isOpen(Channel::Sms))->toBeFalse();
});

it('fails fast on a permanent provider error', function () {
    Http::fake(['*' => Http::response(['error' => 'bad request'], 422)]);

    $notification = Notification::factory()->forChannel(Channel::Sms)
        ->withStatus(Status::Queued)->create(['content' => 'hi']);

    SendNotification::dispatchSync($notification);

    $notification->refresh();

    expect($notification->status)->toBe(Status::Failed)
        ->and($notification->deliveryAttempts()->where('status', Status::Failed->value)->count())->toBe(1)
        ->and($notification->deliveryAttempts()->first()?->response_code)->toBe(422);
});

it('re-throws a transient error (for retry) and records the attempt + breaker failure', function () {
    Http::fake(['*' => Http::response('upstream down', 500)]);

    $notification = Notification::factory()->forChannel(Channel::Sms)
        ->withStatus(Status::Queued)->create(['content' => 'hi']);

    try {
        SendNotification::dispatchSync($notification);
        throw new RuntimeException('expected a transient exception');
    } catch (TransientProviderException) {
        // expected — the queue would retry with backoff
    }

    $notification->refresh();

    expect($notification->deliveryAttempts()->where('status', Status::Failed->value)->count())->toBe(1)
        ->and($notification->deliveryAttempts()->first()?->response_code)->toBe(500);
});

it('does not re-send a terminal notification', function () {
    Http::fake(['*' => Http::response(['messageId' => 'x'], 200)]);

    $notification = Notification::factory()->forChannel(Channel::Sms)
        ->withStatus(Status::Sent)->create();

    SendNotification::dispatchSync($notification);

    expect($notification->fresh()?->deliveryAttempts()->count())->toBe(0);
    Http::assertNothingSent();
});
