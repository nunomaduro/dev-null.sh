<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyEpochClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyEpochClaim>
 */
final class DailyEpochClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'user_id' => User::factory(),
            'epoch_date' => $now->toDateString(),
            'activity_score' => 50,
            'allocated_bytes' => 25,
            'claimed_at' => null,
            'expires_at' => $now->addDay(),
        ];
    }
}
