<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

final readonly class FollowersYouKnowQuery
{
    public const int PREVIEW_COUNT = 3;

    /**
     * A user's followers whom the viewer follows too, as the web lists them: the newest follow of the user first.
     *
     * @return BelongsToMany<User, User>
     */
    public function builder(User $user, User $viewer): BelongsToMany
    {
        $following = $viewer->following();

        return $user->followers()
            ->whereIn('users.id', $following->newPivotQuery()->select($following->getRelatedPivotKeyName()))
            ->latest('follows.created_at')
            ->latest('users.id');
    }

    /**
     * The few the profile names, the most followed first so the viewer is likelier to recognise them, and how many
     * there are in all.
     *
     * @return array{users: Collection<int, User>, count: int}
     */
    public function preview(User $user, User $viewer): array
    {
        $following = $viewer->following();
        $followers = $user->followers();

        $known = $followers->newPivotQuery()
            ->select($followers->getRelatedPivotKeyName())
            ->whereIn($followers->getRelatedPivotKeyName(), $following->newPivotQuery()->select($following->getRelatedPivotKeyName()));

        $count = $known->count();

        if ($count === 0) {
            return ['users' => new Collection(), 'count' => 0];
        }

        $users = User::query()
            ->whereIn('id', $known)
            ->addSelect(['followers_count' => $following->newPivotStatement()
                ->selectRaw('count(*)')
                ->whereColumn($following->getQualifiedRelatedPivotKeyName(), 'users.id')])
            ->orderByDesc('followers_count')
            ->orderBy('users.id')
            ->limit(self::PREVIEW_COUNT)
            ->get()
            ->toBase();

        return ['users' => $users, 'count' => $count];
    }
}
