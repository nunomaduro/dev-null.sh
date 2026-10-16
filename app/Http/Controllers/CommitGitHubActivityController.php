<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowCommitGitHubActivityRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\GitHubActivityQuery;
use Illuminate\Http\JsonResponse;

final readonly class CommitGitHubActivityController
{
    public function show(ShowCommitGitHubActivityRequest $request, Commit $commit, GitHubActivityQuery $activity): JsonResponse
    {
        return response()->json([
            'activity' => $commit->user instanceof User ? $activity->get($commit->user) : null,
        ]);
    }
}
