<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;

final readonly class CommitPageQuery
{
    /**
     * The commit as its page shows it to the viewer: the replies the viewer may see, and everything its card shows
     * them. A guest is a viewer who starred, saved and voted on nothing.
     */
    public function get(Commit $commit, ?User $viewer): Commit
    {
        return Commit::query()
            ->withVisibleReplyCount($viewer)
            ->withCardDataFor($viewer)
            ->findOrFail($commit->id);
    }
}
