<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commit>
 */
final class CommitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'body' => fake()->realText(280),
            'status' => CommitStatus::Approved,
            'mentions' => [],
        ];
    }

    /**
     * Replies join the thread of their parent, and commits start with the hot score of a commit nobody engaged with yet.
     */
    public function configure(): self
    {
        return $this->afterMaking(function (Commit $commit): void {
            if ($commit->parent_id !== null && $commit->root_id === null) {
                $commit->forceFill(['root_id' => Commit::query()->withTrashed()->findOrFail($commit->parent_id)->threadRootId()]);
            }

            if ($commit->getAttribute('hot_score') === null) {
                $commit->forceFill(['hot_score' => resolve(TheAlgorithm::class)->score($commit->created_at ?? now(), 0, 0)]);
            }
        });
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommitStatus::Pending,
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommitStatus::Rejected,
        ]);
    }
}
