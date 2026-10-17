<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowCommitPhotoRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitAncestorsQuery;
use App\Queries\CommitRepliesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CommitPhotoController
{
    public function show(ShowCommitPhotoRequest $request, #[CurrentUser] ?User $currentUser, User $user, Commit $commit, int $photo, CommitAncestorsQuery $ancestors, CommitRepliesQuery $replies): RedirectResponse|Response
    {
        if (! $currentUser instanceof User) {
            return to_route('commit.show', [$user, $commit]);
        }

        $focusedCommit = null;
        $getCommit = function () use (&$focusedCommit, $commit, $currentUser): Commit {
            return $focusedCommit ??= Commit::query()
                ->withVisibleReplyCount($currentUser)
                ->withSavedState($currentUser)
                ->with(['user', 'linkPreview', 'images'])
                ->withQuotedCommitFor($currentUser)
                ->withStarsFor($currentUser)
                ->findOrFail($commit->id);
        };

        return Inertia::render('commit/show', [
            'commit' => $getCommit,
            'ancestors' => fn (): Collection => $ancestors->get($commit, $currentUser),
            'replies' => fn (): CursorPaginator => $replies->paginate($commit, $currentUser),
            'photo' => fn (): array => [
                'commit' => $getCommit(),
                'index' => $photo - 1,
            ],
        ]);
    }
}
