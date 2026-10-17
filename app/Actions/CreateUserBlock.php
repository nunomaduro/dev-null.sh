<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateUserBlock
{
    public function __construct(private UpdateCommitHotScores $updateCommitHotScores)
    {
        //
    }

    /**
     * Blocking works both ways, so it also ends the follows and removes the stars in both directions.
     */
    public function handle(User $user, User $blocked): void
    {
        DB::transaction(function () use ($user, $blocked): void {
            $user->blocking()->syncWithoutDetaching([$blocked->id]);

            $user->following()->detach($blocked->id);
            $blocked->following()->detach($user->id);

            $this->updateCommitHotScores->handle([
                ...$this->deleteStars($blocked, $user),
                ...$this->deleteStars($user, $blocked),
            ]);
        });
    }

    /**
     * Removes the stars the stargazer gave the author's commits, with their notifications, so none of them come back
     * after an unblock.
     *
     * @return array<int, string> The ids of the commits that lost a star.
     */
    private function deleteStars(User $stargazer, User $author): array
    {
        $commitIds = $stargazer->starredCommits()
            ->withTrashed()
            ->where('commits.user_id', $author->id)
            ->get(['commits.id'])
            ->map(fn (Commit $commit): string => $commit->id)
            ->all();

        if ($commitIds === []) {
            return [];
        }

        $stargazer->starredCommits()->detach($commitIds);

        Notification::query()
            ->where('type', NotificationType::Starred)
            ->where('actor_id', $stargazer->id)
            ->whereIn('commit_id', $commitIds)
            ->delete();

        return $commitIds;
    }
}
