<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ShowApiCommitRequest;
use App\Http\Resources\CommitResource;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitAncestorsQuery;
use App\Queries\CommitPageQuery;
use Illuminate\Container\Attributes\CurrentUser;

final readonly class CommitController
{
    /**
     * A signed request gets the commit as its page shows it to that user, with the commits it replies to above it.
     */
    public function show(ShowApiCommitRequest $request, #[CurrentUser] ?User $currentUser, Commit $commit, CommitPageQuery $page, CommitAncestorsQuery $ancestors): CommitResource
    {
        if ($currentUser instanceof User) {
            $commit = $page->get($commit, $currentUser);

            return CommitResource::make($commit)->additional([
                'ancestors' => CommitResource::collection($ancestors->get($commit, $currentUser)),
            ]);
        }

        return CommitResource::make(Commit::query()
            ->withPublicPreview()
            ->whereKey($commit->id)
            ->sole());
    }
}
