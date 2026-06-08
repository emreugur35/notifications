<?php

declare(strict_types=1);

namespace Database\Factories;

use Domain\Notifications\Enums\Channel;
use Domain\Notifications\Enums\Priority;
use Domain\Notifications\Enums\Status;
use Domain\Notifications\Models\Notification;
use Domain\Notifications\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /** @var class-string<Notification> */
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $channel = $this->faker->randomElement(Channel::cases());

        return [
            'batch_id' => null,
            'recipient' => $channel === Channel::Sms
                ? $this->faker->e164PhoneNumber()
                : $this->faker->safeEmail(),
            'channel' => $channel,
            'content' => $this->faker->sentence(),
            'priority' => $this->faker->randomElement(Priority::cases()),
            'status' => Status::Pending,
            'idempotency_key' => $this->faker->optional()->uuid(),
            'provider_message_id' => null,
            'scheduled_at' => null,
            'attempts' => 0,
            'metadata' => [],
        ];
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    public function withStatus(Status $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => Status::Pending,
            'scheduled_at' => $this->faker->dateTimeBetween('now', '+1 week'),
        ]);
    }

    public function forBatch(NotificationBatch|string $batch): static
    {
        $batchId = $batch instanceof NotificationBatch ? $batch->id : $batch;

        return $this->state(fn (): array => ['batch_id' => $batchId]);
    }
}
