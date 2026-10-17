<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The web's lists show each commit with its newest reply or quote, which CommitBuilder::withReplyPreviews() hands
 * over as arrays. The API loads those replies again as commits, all at once, so they come in the shape of every
 * other commit.
 */
final readonly class ReplyPreviewsQuery
{
    /**
     * @param  iterable<array-key, Model>  $commits
     */
    public function load(iterable $commits, ?User $viewer): void
    {
        $commits = new Collection($commits)
            ->whereInstanceOf(Commit::class)
            ->filter(fn (Commit $commit): bool => $commit->hasAttribute('reply_preview'));

        $ids = $commits->flatMap(fn (Commit $commit): array => array_column($this->preview($commit)['data'], 'id'));

        $replies = $ids->isEmpty() ? new Collection() : Commit::query()
            ->withTrashed()
            ->whereKey($ids->all())
            ->withVisibleReplyCount($viewer)
            ->withCardDataFor($viewer)
            ->without('quotedCommit')
            ->get()
            ->keyBy('id');

        foreach ($commits as $commit) {
            $commit->setRelation('replyPreview', new Collection(array_values(array_filter(array_map(
                fn (array $reply): ?Commit => $replies->get($reply['id']),
                $this->preview($commit)['data'],
            )))));
        }
    }

    /**
     * @return array{data: list<array{id: string}>, next_cursor: string|null}
     */
    private function preview(Commit $commit): array
    {
        /** @var array{data: list<array{id: string}>, next_cursor: string|null} $preview */
        $preview = $commit->getAttribute('reply_preview');

        return $preview;
    }
}
