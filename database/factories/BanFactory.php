<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ban;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ban>
 */
final class BanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_id' => (string) fake()->unique()->numberBetween(1, 999_999_999),
        ];
    }
}
