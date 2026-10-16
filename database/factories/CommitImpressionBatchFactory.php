<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommitImpressionBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommitImpressionBatch>
 */
final class CommitImpressionBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'batch_id' => (string) Str::uuid(),
            'created_at' => now(),
        ];
    }
}
