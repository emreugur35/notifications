<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /** @var class-string<Template> */
    protected $model = Template::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(),
            'channel' => $this->faker->randomElement(Channel::cases()),
            'subject' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
        ];
    }
}
