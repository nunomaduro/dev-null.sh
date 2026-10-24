<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CommitStatus;
use App\Enums\RepliesPerPage;
use App\Models\Commit;
use App\Models\User;
use App\Services\CommitTranslations;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor;

/**
 * @extends Builder<Commit>
 */
final class CommitBuilder extends Builder
{
    /**
     * Every column but the embedding, in the table's order.
     */
    private const array COLUMNS_BUT_EMBEDDING = [
        'id', 'user_id', 'body', 'created_at', 'updated_at', 'status', 'parent_id', 'deleted_at', 'link_preview_id',
        'quoted_commit_id', 'mentions', 'ai_decision', 'ai_categories', 'root_id', 'hot_score', 'pasteis_count',
        'topic_decision',
    ];

    /**
     * Embeddings are only ever compared within the database, so commits are loaded without theirs rather than carry
     * kilobytes of numbers each that nothing reads.
     *
     * @param  array<int, Expression|string>|string  $columns
     * @return array<int, Commit>
     */
    public function getModels($columns = ['*']): array
    {
        $selected = $this->query->columns;

        $this->query->columns = array_merge(...array_map(
            fn (Expression|string $column): array => $this->isEveryColumn($column)
                ? array_map($this->qualifyColumn(...), self::COLUMNS_BUT_EMBEDDING)
                : [$column],
            $selected ?? (array) $columns,
        ));

        try {
            return parent::getModels();
        } finally {
            $this->query->columns = $selected;
        }
    }

    /**
     * Approved commits are visible to everyone, guests included, while authors may also see their own pending or rejected commits.
     * A block hides each one's commits from the other.
     */
    public function visibleTo(?User $user): self
    {
        if (! $user instanceof User) {
            return $this->where($this->qualifyColumn('status'), CommitStatus::Approved);
        }

        return $this->where(fn (Builder $query): Builder => $query
            ->where($this->qualifyColumn('status'), CommitStatus::Approved)
            ->orWhere($this->qualifyColumn('user_id'), $user->id))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull($this->qualifyColumn('user_id'))
                ->orWhereNotIn($this->qualifyColumn('user_id'), $user->idsAcrossBlocks()));
    }

    /**
     * Muting only quiets what the user comes across: the commits stay reachable from their author's profile and link.
     */
    public function withoutMutedAuthorsFor(?User $user): self
    {
        return $user instanceof User ? $this->where(fn (Builder $query): Builder => $query
            ->whereNull($this->qualifyColumn('user_id'))
            ->orWhereNotIn($this->qualifyColumn('user_id'), $user->mutedIds())) : $this;
    }

    /**
     * Deleted commits stay in a thread as tombstones while they still have replies the given user can see.
     * Replies by muted people are left out along with the replies under them.
     * Replies and quotes are checked in one exists, which stays a lookup per tombstone: two separate ones get planned as
     * hashes over every visible commit.
     */
    public function visibleInThreadTo(?User $user): self
    {
        $replies = (new Commit)->setTable('surviving_replies')->newQuery()
            ->from((new Commit)->getTable().' as surviving_replies')
            ->withTrashed()
            ->visibleTo($user)
            ->withoutMutedAuthorsFor($user)
            ->where(fn (self $query): self => $query
                ->whereColumn('surviving_replies.parent_id', $this->qualifyColumn('id'))
                ->orWhereColumn('surviving_replies.quoted_commit_id', $this->qualifyColumn('id')));

        return $this->withTrashed()
            ->visibleTo($user)
            ->withoutMutedAuthorsFor($user)
            ->where(fn (self $query): self => $query
                ->whereNull($query->getModel()->getQualifiedDeletedAtColumn())
                ->orWhereExists($replies));
    }

    public function topLevel(): self
    {
        return $this->whereNull('parent_id');
    }

    /**
     * Commits created after the given one, including those created in the same second with a later, time-ordered id.
     */
    public function newerThan(Commit $commit): self
    {
        return $this->where(fn (self $query): self => $query
            ->where($this->qualifyColumn('created_at'), '>', $commit->created_at)
            ->orWhere(fn (self $query): self => $query
                ->where($this->qualifyColumn('created_at'), $commit->created_at)
                ->where($this->qualifyColumn('id'), '>', $commit->id)));
    }

    public function newestFirst(): self
    {
        return $this->reorder()->latest()->latest('id');
    }

    /**
     * Counts only the replies the given user is allowed to see.
     */
    public function withVisibleReplyCount(?User $user): self
    {
        $replies = (new Commit)->setTable('thread_replies')->newQuery()
            ->from((new Commit)->getTable().' as thread_replies')
            ->selectRaw('count(*)')
            ->where(fn (self $query): self => $query
                ->whereColumn('thread_replies.parent_id', $this->qualifyColumn('id'))
                ->orWhereColumn('thread_replies.quoted_commit_id', $this->qualifyColumn('id')))
            ->visibleInThreadTo($user);

        return $this->addSelect([$this->qualifyColumn('*')])->selectSub($replies, 'replies_count');
    }

    /**
     * The newest replies and quotes of each commit, as many as the viewer shows at a time, are picked first, and only
     * the picked ones are then loaded as cards, so a commit with thousands of replies does not have every one of them
     * counted and checked for the viewer.
     */
    public function withReplyPreviews(?User $user): self
    {
        $count = RepliesPerPage::of(request())->value;
        $newest = fn (HasMany $relation): HasMany => $this->newestVisibleOf($relation, $user, $count);

        return $this->with(['replies' => $newest, 'quotes' => $newest])
            ->withContinuationId($user)
            ->afterQuery(fn (Collection $commits): Collection => $this->attachReplyPreviews($commits, $user, $count));
    }

    /**
     * The author's earliest reply to their own commit is shown on its own, so it is left out of the replies count.
     */
    public function withContinuationId(?User $user): self
    {
        $earliest = (new Commit)->setTable('earliest_reply')->newQuery()
            ->from((new Commit)->getTable().' as earliest_reply')
            ->select('earliest_reply.id')
            ->whereColumn('earliest_reply.parent_id', $this->qualifyColumn('id'))
            ->whereColumn('earliest_reply.user_id', $this->qualifyColumn('user_id'))
            ->oldest('earliest_reply.created_at')
            ->oldest('earliest_reply.id')
            ->limit(1);

        $next = (new Commit)->setTable('next_commit')->newQuery()
            ->from((new Commit)->getTable().' as next_commit')
            ->select('next_commit.id')
            ->where('next_commit.id', $earliest)
            ->visibleTo($user)
            ->withoutMutedAuthorsFor($user);

        return $this->addSelect(['continuation_id' => $next])
            ->afterQuery(fn (Collection $commits): Collection => $this->countOutContinuation($commits));
    }

    /**
     * Loads the quoted commit only when the given user may see it, and as a tombstone once deleted.
     */
    public function withQuotedCommitFor(?User $viewer): self
    {
        return $this->with([
            'quotedCommit' => fn (BelongsTo $query): BelongsTo => $query
                ->withTrashed()
                ->where(fn (self $query): self => $query->visibleTo($viewer))
                ->with(['user', 'linkPreview', 'images'])
                ->withPollFor($viewer),
        ]);
    }

    public function approved(): self
    {
        return $this->where('status', CommitStatus::Approved);
    }

    /**
     * Loads what anyone may see around the commits, like Commit::loadPublicPreview(): approved replies, stars, images, and the parent only when approved.
     */
    public function withPublicPreview(): self
    {
        return $this
            ->withVisibleReplyCount(null)
            ->withCount('stars')
            ->with(['user', 'linkPreview', 'images', 'parent' => fn (BelongsTo $query): BelongsTo => $query->where('status', CommitStatus::Approved)]);
    }

    public function pending(): self
    {
        return $this->where('status', CommitStatus::Pending);
    }

    /**
     * Guests see how many stars a commit has, without having starred any. Stars are counted on the pivot table alone:
     * the relation would otherwise join the users table for every star.
     */
    public function withStarsFor(?User $viewer): self
    {
        $stars = $this->getModel()->stars();

        return $this
            ->addSelect([
                'stars_count' => $stars->newPivotStatement()
                    ->selectRaw('count(*)')
                    ->whereColumn($stars->getQualifiedForeignPivotKeyName(), $this->qualifyColumn('id')),
                'starred' => $this->whetherViewerHasRow(
                    $stars->newPivotStatement(),
                    $stars->getQualifiedForeignPivotKeyName(),
                    $stars->getQualifiedRelatedPivotKeyName(),
                    $viewer,
                ),
            ])
            ->withCasts(['starred' => 'bool']);
    }

    /**
     * Loads the poll with each option's vote count and whether the viewer chose it, in two queries for any number of commits.
     */
    public function withPollFor(?User $viewer): self
    {
        return $this
            ->with(['poll.options' => fn (HasMany $options): HasMany => $options
                ->withCount('votes')
                ->withExists(['votes as voted' => fn (Builder $query): Builder => $viewer instanceof User
                    ? $query->where('user_id', $viewer->id)
                    : $query->whereRaw('1 = 0')])])
            ->afterQuery(function (Collection $commits) use ($viewer): void {
                foreach ($commits->whereInstanceOf(Commit::class) as $commit) {
                    if ($commit->relationLoaded('poll')) {
                        $commit->poll?->forceFill(['is_own' => $commit->user_id === $viewer?->id]);
                    }
                }
            });
    }

    /**
     * Everything a commit card shows for the given viewer, so every list renders its commits the same way.
     * The reply count is left out, as its presence is what gives a card its thread actions.
     */
    public function withCardDataFor(?User $viewer): self
    {
        return $this
            ->with(['user', 'linkPreview', 'images'])
            ->withQuotedCommitFor($viewer)
            ->withStarsFor($viewer)
            ->withPollFor($viewer)
            ->afterQuery(fn (Collection $commits) => resolve(CommitTranslations::class)->attach($commits));
    }

    /**
     * Looked up commit by commit rather than with exists(), which the database answers by first gathering every row
     * the viewer has, however many stars that is.
     */
    private function whetherViewerHasRow(QueryBuilder $rows, string $commitColumn, string $userColumn, ?User $viewer): QueryBuilder
    {
        $rows->selectRaw('count(*) > 0')->whereColumn($commitColumn, $this->qualifyColumn('id'));

        return $viewer instanceof User ? $rows->where($userColumn, $viewer->id) : $rows->whereRaw('1 = 0');
    }

    /**
     * A bare * only stands for the commit's columns when no other table is joined in.
     */
    private function isEveryColumn(Expression|string $column): bool
    {
        return $this->query->from === $this->getModel()->getTable()
            && ($column === $this->qualifyColumn('*') || ($column === '*' && $this->query->joins === null));
    }

    /**
     * @param  HasMany<Commit, Commit>  $relation
     * @return HasMany<Commit, Commit>
     */
    private function newestVisibleOf(HasMany $relation, ?User $user, int $count): HasMany
    {
        return $relation
            ->visibleInThreadTo($user)
            ->select($relation->getRelated()->qualifyColumns(['id', 'parent_id', 'quoted_commit_id', 'created_at']))
            ->newestFirst()
            ->limit($count + 1);
    }

    /**
     * @param  Collection<int, Commit>  $commits
     * @return Collection<int, Commit>
     */
    private function countOutContinuation(Collection $commits): Collection
    {
        foreach ($commits as $commit) {
            if (! $commit->trashed() && $commit->hasAttribute('replies_count') && $commit->hasAttribute('continuation_id') && $commit->continuation_id !== null) {
                $commit->setAttribute('replies_count', $commit->replies_count - 1);
            }
        }

        return $commits;
    }

    /**
     * @param  Collection<int, Commit>  $commits
     * @return Collection<int, Commit>
     */
    private function attachReplyPreviews(Collection $commits, ?User $user, int $count): Collection
    {
        $previewed = $commits->filter(fn (Commit $commit): bool => $commit->relationLoaded('replies') && $commit->relationLoaded('quotes'));
        $newest = $previewed->mapWithKeys(fn (Commit $commit): array => [$commit->id => $commit->replies->merge($commit->quotes)
            ->reject(fn (Commit $reply): bool => $reply->id === $commit->continuation_id)
            ->sortByDesc(fn (Commit $reply): string => $reply->created_at->format('Y-m-d H:i:s.u').$reply->id)
            ->take($count)
            ->modelKeys()]);
        $ids = $newest->flatten()->all();
        $cards = $ids === [] ? new Collection() : Commit::query()
            ->withTrashed()
            ->whereKey($ids)
            ->withVisibleReplyCount($user)
            ->withContinuationId($user)
            ->withCardDataFor($user)
            ->without('quotedCommit')
            ->get()
            ->keyBy('id');

        foreach ($previewed as $commit) {
            $replies = collect($newest[$commit->id])->map(fn (string $id): ?Commit => $cards->get($id))->filter()->values();
            $oldest = $replies->last();
            $cursor = $oldest instanceof Commit && $commit->replies_count > $replies->count()
                ? new Cursor(['created_at' => $oldest->getRawOriginal('created_at'), 'id' => $oldest->id])->encode()
                : null;

            $commit->setAttribute('reply_preview', [
                'data' => $replies->map(fn (Commit $reply): array => $reply->toArray())->all(),
                'next_cursor' => $cursor,
                'prev_cursor' => null,
            ]);
            $commit->unsetRelation('replies')->unsetRelation('quotes');
        }

        return $commits;
    }
}
