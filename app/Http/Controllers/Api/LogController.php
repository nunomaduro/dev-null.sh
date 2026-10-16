<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Log;
use App\Http\Requests\ShowLogRequest;
use App\Http\Resources\CommitResource;
use App\Models\Commit;
use App\Models\User;
use App\Queries\LatestLogCommitsQuery;
use App\Queries\SuggestedCommitsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

final readonly class LogController
{
    /**
     * Following, Latest or Trending, as the web shows it, 20 commits a page. Trending is ranked rather than newest
     * first, so it also says which commit was newest when it was loaded, and with ?since= it offers the commits newer
     * than that one, other than the user's own, the way the web offers them above it. Every log counts the commits
     * newer than ?since=. An empty Following log suggests a few commits, as on the web.
     */
    public function show(ShowLogRequest $request, #[CurrentUser] User $user, Log $log, LatestLogCommitsQuery $latest, SuggestedCommitsQuery $suggested): AnonymousResourceCollection
    {
        $since = $request->since();

        return CommitResource::collection($log->query()->builder($user)->cursorPaginate(20))->additional([
            'log' => $log,
            'is_ranked' => $log->isRanked(),
            'newest_commit_id' => $log->isRanked() ? $latest->newestId($log, $user) : null,
            ...$since instanceof Commit ? [
                'latest_commits' => CommitResource::collection($latest->get($log, $user, $since, null)),
                'new_commits_count' => $latest->count($log, $user, $since),
            ] : [],
            'suggestions' => CommitResource::collection($log === Log::Following && ! $user->following()->exists()
                ? $suggested->get($user)
                : new Collection()),
        ]);
    }
}
