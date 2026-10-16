<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListApiUserCommitsRequest;
use App\Http\Resources\CommitResource;
use App\Models\User;
use App\Queries\CommitBuilder;
use App\Queries\ReplyPreviewsQuery;
use App\Queries\UserCommitsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class UserCommitController
{
    /**
     * A signed request gets the profile's Commits or Replies tab as the web shows it to that user.
     */
    public function index(ListApiUserCommitsRequest $request, #[CurrentUser] ?User $currentUser, User $user, UserCommitsQuery $commits, ReplyPreviewsQuery $replyPreviews): AnonymousResourceCollection
    {
        if ($currentUser instanceof User) {
            $signedCommits = $commits->builder($user, $currentUser, $request->replies())
                ->cursorPaginate(20)
                ->appends($request->only('replies'));

            $replyPreviews->load($signedCommits->items(), $currentUser);

            return CommitResource::collection($signedCommits);
        }

        return CommitResource::collection($user->commits()
            ->approved()
            ->when(
                $request->replies(),
                fn (CommitBuilder $query): CommitBuilder => $query->whereNotNull('parent_id'),
                fn (CommitBuilder $query): CommitBuilder => $query->topLevel(),
            )
            ->withPublicPreview()
            ->latest()
            ->latest('id')
            ->cursorPaginate(20)
            ->appends($request->only('replies')));
    }
}
