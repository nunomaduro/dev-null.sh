<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListCommitSearchRequest;
use App\Models\User;
use App\Queries\CommitSearchQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Throwable;

final readonly class CommitSearchController
{
    /**
     * The search needs OpenAI to read the term, so while it can't be reached the client is told the search is down,
     * rather than that nothing matched.
     */
    public function index(ListCommitSearchRequest $request, #[CurrentUser] ?User $user, CommitSearchQuery $search): JsonResponse
    {
        try {
            $commits = $search->get($request->string('q')->value(), $user, $request->limit());
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json(['message' => 'Commit search is unavailable right now. Try again in a moment.'], 503);
        }

        return response()->json([
            'commits' => $commits,
        ]);
    }
}
