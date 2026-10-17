<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LevelRewardClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LevelRewardClaim>
 */
final class LevelRewardClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'level' => 5,
            'bytes' => 25,
        ];
    }
}
