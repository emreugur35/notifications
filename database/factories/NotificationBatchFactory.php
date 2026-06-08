<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationBatch>
 */
class NotificationBatchFactory extends Factory
{
    /** @var class-string<NotificationBatch> */
    protected $model = NotificationBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'total_count' => 0,
            'pending_count' => 0,
            'queued_count' => 0,
            'processing_count' => 0,
            'sent_count' => 0,
            'delivered_count' => 0,
            'failed_count' => 0,
            'cancelled_count' => 0,
        ];
    }
}
