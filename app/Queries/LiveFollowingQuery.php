<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class LiveFollowingQuery
{
    /**
     * The people the user follows who are live, soonest ending first, each with their latest commit to say what they
     * are up to. Two queries however many are live: the people with the id of their latest commit, then those commits.
     * Each person carries only what the rail and the aside draw, as both reload it every 30 seconds.
     *
     * @return Collection<int, array<mixed>>
     */
    public function get(User $user, int $limit = 24): Collection
    {
        $latestCommit = Commit::query()
            ->select('commits.id')
            ->whereColumn('commits.user_id', 'users.id')
            ->topLevel()
            ->approved()
            ->newestFirst()
            ->limit(1);

        // Followed ids rather than the following relation, which would send the follow along as each person's pivot.
        $people = User::query()
            ->select('users.*')
            ->addSelect(['latest_commit_id' => $latestCommit])
            ->whereIn('users.id', $user->following()->newPivotQuery()->select('followed_id'))
            ->where('users.live_ends_at', '>', now())
            ->whereNotNull('users.live_platform')
            ->whereNotNull('users.live_url')
            ->whereNotIn('users.id', $user->mutedIds())
            ->whereNotIn('users.id', $user->idsAcrossBlocks())
            ->oldest('users.live_ends_at')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        $ids = $people->pluck('latest_commit_id')->filter()->values()->all();

        $commits = $ids === [] ? new Collection() : Commit::query()
            ->select(['id', 'body', 'created_at'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        return $people->toBase()->map(function (User $person) use ($commits): array {
            $id = $person->getAttribute('latest_commit_id');
            $commit = is_string($id) ? $commits->get($id) : null;

            return [
                'id' => $person->id,
                'username' => $person->username,
                'name' => $person->name,
                'avatar_url' => $person->avatar_url,
                'lane' => $person->lane(),
                'live' => $person->liveStream()?->toArray(),
                'latest_commit' => $commit instanceof Commit ? [
                    'id' => $commit->id,
                    'body' => Str::limit($commit->body, Commit::PREVIEW_LENGTH, '…'),
                    'created_at' => $commit->created_at->toJSON(),
                ] : null,
            ];
        });
    }
}
