<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommitStar;
use App\Actions\DeleteCommitStar;
use App\Http\Requests\CreateCommitStarRequest;
use App\Http\Requests\DeleteCommitStarRequest;
use App\Http\Requests\ListCommitStarsRequest;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class CommitStarController
{
    /**
     * Who starred the commit, newest first. People across a block with the author are left out, like everywhere else.
     */
    public function index(ListCommitStarsRequest $request, #[CurrentUser] User $user, Commit $commit): JsonResponse
    {
        $stargazers = $commit->stars()
            ->whereNotIn('users.id', $user->idsAcrossBlocks())
            ->latest('stars.created_at')
            ->latest('users.id')
            ->cursorPaginate(20);

        foreach ($stargazers->items() as $stargazer) {
            $stargazer->makeHidden('pivot');
        }

        return response()->json($stargazers);
    }

    public function store(CreateCommitStarRequest $request, #[CurrentUser] User $user, Commit $commit, CreateCommitStar $action): JsonResponse
    {
        $action->handle($user, $commit);

        return response()->json([
            'starred' => true,
            'stars_count' => $commit->stars()->count(),
        ]);
    }

    public function destroy(DeleteCommitStarRequest $request, #[CurrentUser] User $user, Commit $commit, DeleteCommitStar $action): JsonResponse
    {
        $action->handle($user, $commit);

        return response()->json([
            'starred' => false,
            'stars_count' => $commit->stars()->count(),
        ]);
    }
}
