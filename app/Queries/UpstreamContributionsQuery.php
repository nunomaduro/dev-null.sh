<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final readonly class UpstreamContributionsQuery
{
    public const string CacheKey = 'upstream:contributions';

    /**
     * The changes GitHub counts for the developer on the source repository's production branch, matched by the
     * immutable account ID so a rename keeps the progress. Nothing cached yet means none.
     */
    public function countFor(User $user): int
    {
        /** @var array<int|string, int> $contributions */
        $contributions = Cache::memo()->get(self::CacheKey, []);

        return $contributions[$user->github_id] ?? 0;
    }
}
