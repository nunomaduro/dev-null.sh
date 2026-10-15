<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitByReferenceQuery;
use Laravel\Mcp\Response;

final readonly class CommitResolver
{
    public function __construct(private CommitByReferenceQuery $query)
    {
        //
    }

    /**
     * Commits the user cannot see look like they do not exist.
     */
    public function resolve(User $user, string $reference): Commit|Response
    {
        $commits = $this->query->get($user, mb_trim($reference));

        if ($commits === null) {
            return Response::error('That is not a commit hash, ID, or link.');
        }

        if ($commits->count() > 1) {
            return Response::error('That hash matches more than one commit: use the full ID instead.');
        }

        return $commits->first() ?? Response::error('No commit found for that reference.');
    }
}
