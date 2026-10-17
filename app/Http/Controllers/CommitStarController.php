<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommitStar;
use App\Actions\DeleteCommitStar;
use App\Http\Requests\CreateCommitStarRequest;
use App\Http\Requests\DeleteCommitStarRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\UserStarsCountQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class CommitStarController
{
    /**
     * Returns the author's new total too, so their profile updates without a reload.
     */
    public function store(CreateCommitStarRequest $request, #[CurrentUser] User $user, Commit $commit, CreateCommitStar $action, UserStarsCountQuery $stars): JsonResponse
    {
        $action->handle($user, $commit);

        return response()->json([
            'starred' => true,
            'stars_count' => $commit->stars()->count(),
            'author' => [
                'id' => $commit->user_id,
                'stars_received' => $commit->user instanceof User ? $stars->get($commit->user, $user) : null,
            ],
        ]);
    }

    public function destroy(DeleteCommitStarRequest $request, #[CurrentUser] User $user, Commit $commit, DeleteCommitStar $action, UserStarsCountQuery $stars): JsonResponse
    {
        $action->handle($user, $commit);

        return response()->json([
            'starred' => false,
            'stars_count' => $commit->stars()->count(),
            'author' => [
                'id' => $commit->user_id,
                'stars_received' => $commit->user instanceof User ? $stars->get($commit->user, $user) : null,
            ],
        ]);
    }
}
