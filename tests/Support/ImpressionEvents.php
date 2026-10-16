<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Commit;
use App\Models\User;
use App\Services\CommitImpressionToken;
use RuntimeException;

final readonly class ImpressionEvents
{
    /**
     * @param  iterable<int, Commit>  $commits
     * @return list<array{commit_id: string, token: string}>
     */
    public static function for(User $viewer, iterable $commits): array
    {
        $events = [];

        foreach ($commits as $commit) {
            $issued = resolve(CommitImpressionToken::class)->issue($commit, $viewer);

            throw_if($issued === null, RuntimeException::class, 'The impression fixture must be eligible when its token is issued.');

            $events[] = ['commit_id' => $commit->id, 'token' => $issued['token']];
        }

        return $events;
    }
}
