<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
final class TopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'value' => fake()->unique()->words(3, true),
            'last_applied_at' => now(),
        ];
    }
}
