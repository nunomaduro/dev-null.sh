<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use App\Models\User;
use App\Queries\CommitBuilder;
use App\Services\CommitImpressionToken;
use Illuminate\Support\Facades\DB;

final readonly class CreateCommitImpressions
{
    public const int REPEAT_WINDOW_MINUTES = 30;

    public const int MAX_BATCH_SIZE = 50;

    public function __construct(private CommitImpressionToken $tokens)
    {
        //
    }

    /**
     * A receipt, viewer cooldowns, and counters commit together. Sorted locks also serialize overlapping batches,
     * including the first view when a viewer has no cooldown row yet. Replaying a receipt never extends a cooldown.
     *
     * @param  list<array{commit_id: string, token: string}>  $impressions
     */
    public function handle(User $user, string $batchId, array $impressions): void
    {
        $commitIds = array_column(array_filter($impressions, fn (array $impression): bool => $this->tokens->validFor($user, $impression['commit_id'], $impression['token'])), 'commit_id');

        DB::transaction(function () use ($user, $batchId, $commitIds): void {
            $now = now();

            if (CommitImpressionBatch::query()->insertOrIgnore([
                'user_id' => $user->id,
                'batch_id' => $batchId,
                'created_at' => $now,
            ]) === 0) {
                return;
            }

            $eligibleIds = Commit::query()->whereKey($commitIds)->approved()->visibleTo($user)
                ->where(fn (CommitBuilder $query): CommitBuilder => $query->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->orderBy('id')->lockForUpdate()->get(['id'])
                ->map(fn (Commit $commit): string => $commit->id)->all();

            if ($eligibleIds === []) {
                return;
            }

            CommitImpression::query()->insertOrIgnore(array_map(fn (string $commitId): array => [
                'user_id' => $user->id,
                'commit_id' => $commitId,
                'last_counted_at' => null,
            ], $eligibleIds));

            $countedIds = CommitImpression::query()->where('user_id', $user->id)->whereIn('commit_id', $eligibleIds)
                ->orderBy('commit_id')->lockForUpdate()->get(['commit_id', 'last_counted_at'])
                ->filter(fn (CommitImpression $impression): bool => $impression->last_counted_at === null
                    || $impression->last_counted_at->lessThanOrEqualTo($now->copy()->subMinutes(self::REPEAT_WINDOW_MINUTES)))
                ->pluck('commit_id')->all();

            if ($countedIds === []) {
                return;
            }

            Commit::query()->whereKey($countedIds)->toBase()->increment('impressions_count');

            CommitImpression::query()->where('user_id', $user->id)->whereIn('commit_id', $countedIds)
                ->update(['last_counted_at' => $now]);
        }, 3);
    }
}
