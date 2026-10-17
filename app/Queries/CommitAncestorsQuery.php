<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class CommitAncestorsQuery
{
    /**
     * Walks up the parent links whatever their visibility, so the thread can stop at the first ancestor the user may not see.
     */
    private const string CHAIN = <<<'SQL'
        WITH RECURSIVE chain AS (
            SELECT id, parent_id, 1 AS depth FROM commits WHERE id = ?
            UNION ALL
            SELECT commits.id, commits.parent_id, chain.depth + 1 FROM commits JOIN chain ON commits.id = chain.parent_id WHERE chain.depth < ?
        )
        SELECT id FROM chain
        SQL;

    /**
     * @return Collection<int, Commit>
     */
    public function get(Commit $commit, ?User $user, int $limit = 20): Collection
    {
        $ancestors = new Collection;

        if ($commit->parent_id === null) {
            return $ancestors;
        }

        $visible = Commit::query()
            ->withTrashed()
            ->visibleTo($user)
            ->withCardDataFor($user)
            ->whereRaw('commits.id IN ('.self::CHAIN.')', [$commit->parent_id, $limit + 1])
            ->get()
            ->keyBy('id');

        $parentId = $commit->parent_id;

        while ($parentId !== null && ($parent = $visible->get($parentId)) instanceof Commit) {
            if ($ancestors->count() === $limit) {
                $ancestors->first()?->setRelation('parent', $parent);

                break;
            }

            $ancestors->prepend($parent);
            $parentId = $parent->parent_id;
        }

        return $ancestors;
    }
}
