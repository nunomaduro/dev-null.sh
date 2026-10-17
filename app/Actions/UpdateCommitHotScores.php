<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Services\TheAlgorithm;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCommitHotScores
{
    private const int CHUNK_SIZE = 1000;

    public function __construct(private TheAlgorithm $theAlgorithm)
    {
        //
    }

    /**
     * Rescores the threads the given commits are part of, and for quotes, the thread of the quoted commit too. The
     * threads are found right away, so callers deleting commits can pass them before they are gone.
     *
     * Engagement is every other developer taking part in the thread: replying anywhere in it, or quoting any of its
     * commits others can see, since quotes show among the replies, and below that, starring any of them. Each developer
     * counts once, for the most they did, so a long back-and-forth between two people is not a hit and starring every
     * reply is not either, and pending or deleted commits do not count. The author starring replies does not lift their
     * own commit.
     *
     * Scores are counted once the change is committed, with the commit locked, so two stars landing at the same time
     * cannot each count without the other: whichever counts last sees both.
     *
     * @param  array<int, string>  $commitIds
     */
    public function handle(array $commitIds): void
    {
        $rootIds = collect($commitIds)
            ->chunk(self::CHUNK_SIZE)
            ->flatMap(fn (Collection $ids): EloquentCollection => Commit::query()
                ->withTrashed()
                ->whereKey($ids->all())
                ->with(['quotedCommit' => fn (BelongsTo $query): BelongsTo => $query->withTrashed()->select(['id', 'root_id'])])
                ->get(['id', 'root_id', 'quoted_commit_id']))
            ->flatMap(fn (Commit $commit): array => [$commit->threadRootId(), $commit->quotedCommit?->threadRootId()])
            ->filter()
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();

        if ($rootIds === []) {
            return;
        }

        DB::afterCommit(fn () => DB::transaction(function () use ($rootIds): void {
            foreach (array_chunk($rootIds, self::CHUNK_SIZE) as $chunk) {
                $this->rescore($chunk);
            }
        }));
    }

    /**
     * Each chunk of threads is locked in the order of their ids, as the chunks are, and rescored with a single update.
     *
     * @param  array<int, string>  $rootIds
     */
    private function rescore(array $rootIds): void
    {
        Commit::query()->whereKey($rootIds)->orderBy('id')->lockForUpdate()->get(['id']);

        $commits = Commit::query()->whereKey($rootIds)->topLevel()->get(['id', 'user_id', 'created_at']);

        if ($commits->isEmpty()) {
            return;
        }

        $repliersByThread = $this->usersByThread($this->repliers($commits->modelKeys()));
        $stargazersByThread = $this->usersByThread($this->stargazers($commits->modelKeys()));

        $scores = $commits->flatMap(function (Commit $commit) use ($repliersByThread, $stargazersByThread): array {
            $author = $commit->user_id === null ? [] : [$commit->user_id => true];
            $repliers = array_diff_key($repliersByThread[$commit->id] ?? [], $author);
            $stargazers = array_diff_key($stargazersByThread[$commit->id] ?? [], $author, $repliers);

            return [$commit->id, $this->theAlgorithm->score($commit->created_at, count($stargazers), count($repliers))];
        });

        DB::update(
            'update commits set hot_score = cast(case id '.str_repeat('when ? then ? ', $commits->count()).'end as double precision)'
            .' where id in ('.implode(', ', array_fill(0, $commits->count(), '?')).')',
            [...$scores->all(), ...$commits->modelKeys()],
        );
    }

    /**
     * Who replied in each thread, quoted its top-level commit, or quoted one of its replies, as one indexed query each
     * rather than one query matching any of them, which could not use the indexes.
     *
     * @param  array<int, mixed>  $rootIds
     */
    private function repliers(array $rootIds): Builder
    {
        $engagements = fn (): Builder => DB::table('commits as engagements')
            ->distinct()
            ->where('engagements.status', CommitStatus::Approved)
            ->whereNull('engagements.deleted_at');

        return $engagements()
            ->select(['engagements.root_id as thread_id', 'engagements.user_id'])
            ->whereIn('engagements.root_id', $rootIds)
            ->union($engagements()
                ->select(['engagements.quoted_commit_id as thread_id', 'engagements.user_id'])
                ->whereIn('engagements.quoted_commit_id', $rootIds))
            ->union($engagements()
                ->select(['quoted.root_id as thread_id', 'engagements.user_id'])
                ->join('commits as quoted', 'quoted.id', '=', 'engagements.quoted_commit_id')
                ->whereIn('quoted.root_id', $rootIds)
                ->where('quoted.status', CommitStatus::Approved));
    }

    /**
     * Who starred the top-level commit of each thread, or one of its replies others can see, as one indexed query each.
     *
     * @param  array<int, mixed>  $rootIds
     */
    private function stargazers(array $rootIds): Builder
    {
        return DB::table('stars')
            ->select(['stars.commit_id as thread_id', 'stars.user_id'])
            ->whereIn('stars.commit_id', $rootIds)
            ->union(DB::table('stars')
                ->select(['starred.root_id as thread_id', 'stars.user_id'])
                ->join('commits as starred', 'starred.id', '=', 'stars.commit_id')
                ->whereIn('starred.root_id', $rootIds)
                ->where('starred.status', CommitStatus::Approved)
                ->whereNull('starred.deleted_at'));
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function usersByThread(Builder $query): array
    {
        $usersByThread = [];

        foreach ($query->get() as $row) {
            if (is_string($row->thread_id) && is_string($row->user_id)) {
                $usersByThread[$row->thread_id][$row->user_id] = true;
            }
        }

        return $usersByThread;
    }
}
