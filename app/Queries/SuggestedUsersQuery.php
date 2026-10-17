<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

final readonly class SuggestedUsersQuery
{
    /**
     * People the user does not follow yet: a mix of people their follows already follow and a few
     * popular people picked from the 20 most followed. Either side fills in when the other runs short.
     *
     * The day's candidates are split into pages of that mix, and each round shows the next page,
     * going back to the first one after the last.
     *
     * @return Collection<int, User>
     */
    public function get(User $user, int $limit = 3, int $round = 0): Collection
    {
        $randomizer = $this->randomizerFor($user);

        $following = $user->following();
        $followingIds = $following->newPivotQuery()->select($following->getRelatedPivotKeyName());
        $followedByFollowing = $following->newPivotStatement()
            ->select($following->getRelatedPivotKeyName())
            ->whereIn($following->getForeignPivotKeyName(), $followingIds);

        $network = $this->candidates($user, $followingIds)
            ->joinSub(
                $followedByFollowing->clone()->selectRaw('count(*) as followed_by_following_count')->groupBy($following->getRelatedPivotKeyName()),
                'network',
                'network.'.$following->getRelatedPivotKeyName(),
                '=',
                'users.id',
            )
            ->select(['users.*', 'network.followed_by_following_count'])
            ->addSelect(['followers_count' => $this->followersCount($following)])
            ->whereIn('users.id', $this->committers($user))
            ->orderByDesc('followed_by_following_count')
            ->orderByDesc('followers_count')
            ->latest('users.id')
            ->limit($limit * 10)
            ->get();

        // Ranked before looking for commits, so only the most followed are looked up rather than every candidate.
        $popularPool = User::query()
            ->fromSub($this->candidates($user, $followingIds)
                ->whereNotIn('users.id', $followedByFollowing)
                ->select('users.*')
                ->selectRaw('0 as followed_by_following_count')
                ->addSelect(['followers_count' => $this->followersCount($following)])
                ->orderByDesc('followers_count')
                ->latest('users.id'), 'users')
            ->whereIn('users.id', $this->committers($user))
            ->orderByDesc('followers_count')
            ->latest('users.id')
            ->limit(20)
            ->get();

        $pages = $this->pages($randomizer, $network, $popularPool, $limit);

        return $pages[max(0, $round) % count($pages)];
    }

    /**
     * The last page is topped up from the first one, so a refresh never shows fewer people.
     *
     * @param  Collection<int, User>  $network
     * @param  Collection<int, User>  $popularPool
     * @return non-empty-list<Collection<int, User>>
     */
    private function pages(Randomizer $randomizer, Collection $network, Collection $popularPool, int $limit): array
    {
        $popular = null;
        $pages = [];

        do {
            $networkSlots = $limit - $randomizer->getInt(1, max(1, $limit - 1));
            $popular ??= new Collection($randomizer->shuffleArray($popularPool->all()));

            $pageNetwork = $network->take(max($networkSlots, $limit - $popular->count()));
            $pagePopular = $popular->take($limit - $pageNetwork->count());

            $network = $network->skip($pageNetwork->count());
            $popular = $popular->skip($pagePopular->count());

            $pages[] = $randomizer->shuffleArray([...$pageNetwork, ...$pagePopular]);
        } while ($network->isNotEmpty() || $popular->isNotEmpty());

        $last = array_key_last($pages);

        if ($last > 0 && count($pages[$last]) < $limit) {
            $pages[$last] = $randomizer->shuffleArray([...$pages[$last], ...array_slice($pages[0], 0, $limit - count($pages[$last]))]);
        }

        return array_map(fn (array $page): Collection => new Collection($page), $pages);
    }

    /**
     * @return Builder<User>
     */
    private function candidates(User $user, QueryBuilder $followingIds): Builder
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->whereNotIn('users.id', $followingIds)
            ->whereNotIn('users.id', $user->mutedIds());
    }

    /**
     * @param  BelongsToMany<User, User>  $following
     */
    private function followersCount(BelongsToMany $following): QueryBuilder
    {
        return $following->newPivotStatement()
            ->selectRaw('count(*)')
            ->whereColumn($following->getQualifiedRelatedPivotKeyName(), 'users.id');
    }

    private function committers(User $user): CommitBuilder
    {
        return Commit::query()->visibleTo($user)->topLevel()->select('user_id');
    }

    /**
     * The mix, the popular picks, and their order change once a day, so suggestions don't reshuffle on every page.
     */
    private function randomizerFor(User $user): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($user->id.now()->toDateString())));
    }
}
