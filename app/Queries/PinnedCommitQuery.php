<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;

final readonly class PinnedCommitQuery
{
    /**
     * The commit the user pinned to the top of their profile, unless the viewer, or a guest, may not see it.
     */
    public function get(User $user, ?User $viewer): ?Commit
    {
        if ($user->pinned_commit_id === null) {
            return null;
        }

        return Commit::query()
            ->whereBelongsTo($user)
            ->visibleTo($viewer)
            ->topLevel()
            ->withVisibleReplyCount($viewer)
            ->withReplyPreviews($viewer)
            ->withCardDataFor($viewer)
            ->find($user->pinned_commit_id);
    }
}
