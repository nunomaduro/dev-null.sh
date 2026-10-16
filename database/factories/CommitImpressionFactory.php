<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommitImpression>
 */
final class CommitImpressionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'commit_id' => Commit::factory(),
            'last_counted_at' => now(),
        ];
    }
}
