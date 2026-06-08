<?php

declare(strict_types=1);

use App\Enums\Status;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports counts, throughput, latency percentiles, and queue depth', function () {
    $notification = Notification::factory()->create();

    // 10 successful deliveries with latencies 10..100ms.
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $i => $latency) {
        DeliveryAttempt::factory()->create([
            'notification_id' => $notification->id,
            'attempt_number' => $i + 1,
            'status' => Status::Sent,
            'latency_ms' => $latency,
        ]);
    }

    // 2 failures.
    DeliveryAttempt::factory()->count(2)->create([
        'notification_id' => $notification->id,
        'status' => Status::Failed,
        'latency_ms' => null,
    ]);

    $json = $this->getJson('/api/v1/metrics')->assertOk()->json();

    expect($json['window_minutes'])->toBe(5)
        ->and($json['counts'])->toBe(['sent' => 10, 'failed' => 2])
        ->and($json['throughput_per_min'])->toEqual(2.0) // 10 sent / 5 min
        ->and($json['latency_ms'])->toBe(['p50' => 50, 'p95' => 100, 'p99' => 100])
        ->and($json['queue_depth'])->toHaveKeys(['high', 'normal', 'low']);
});

it('returns null percentiles when there is no recent delivery data', function () {
    $json = $this->getJson('/api/v1/metrics')->assertOk()->json();

    expect($json['counts'])->toBe(['sent' => 0, 'failed' => 0])
        ->and($json['latency_ms'])->toBe(['p50' => null, 'p95' => null, 'p99' => null])
        ->and($json['throughput_per_min'])->toEqual(0.0);
});
