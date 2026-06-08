<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Status;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    /** @var class-string<DeliveryAttempt> */
    protected $model = DeliveryAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'attempt_number' => 1,
            'status' => Status::Sent,
            'response_code' => 200,
            'response_body' => $this->faker->optional()->sentence(),
            'latency_ms' => $this->faker->numberBetween(10, 2000),
            'error' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => Status::Failed,
            'response_code' => $this->faker->randomElement([429, 500, 502, 503]),
            'error' => $this->faker->sentence(),
        ]);
    }
}
