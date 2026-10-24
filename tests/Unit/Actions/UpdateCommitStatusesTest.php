<?php

declare(strict_types=1);

use App\Actions\CreateTomFriendship;
use App\Actions\UpdateCommitStatuses;
use App\Dto\ThreadEngagement;
use App\Enums\CommitStatus;
use App\Jobs\CheckUserLevel;
use App\Jobs\ClassifyCommitTopics;
use App\Jobs\ReplyAsTom;
use App\Models\Commit;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('may approve many commits', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    $commits = Commit::factory()->pending()->count(2)->create();
    $other = Commit::factory()->pending()->create();

    $action = resolve(UpdateCommitStatuses::class);

    $action->handle($commits->modelKeys(), CommitStatus::Approved);

    foreach ($commits as $commit) {
        expect($commit->refresh()->status)->toBe(CommitStatus::Approved);
    }

    expect($other->refresh()->status)->toBe(CommitStatus::Pending);
});

it('queues direct replies to Tom in bulk without per-reply queries', function (): void {
    config(['ai.providers.opencode.key' => 'test', 'ai.providers.openai.key' => 'test']);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);

    $queryCounts = collect([2, 6])->map(function (int $count) use ($tom): int {
        $parents = Commit::factory()->for($tom)->count($count)->create(['body' => 'A developer community.']);
        $replies = $parents->map(fn (Commit $parent): Commit => Commit::factory()->pending()->for($parent, 'parent')->create(['body' => 'Thanks for explaining!']));
        Queue::fake([ReplyAsTom::class, CheckUserLevel::class]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            resolve(UpdateCommitStatuses::class)->handle($replies->modelKeys(), CommitStatus::Approved);
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        Queue::assertPushed(ReplyAsTom::class, $count);
        foreach ($replies as $reply) {
            Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $reply->id);
            expect($reply->fresh()->status)->toBe(CommitStatus::Approved);
        }

        return $queryCount;
    });

    expect($queryCounts[1])->toBe($queryCounts[0]);
});

it('may only update the commits that are still pending', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    $pending = Commit::factory()->pending()->create();
    $rejected = Commit::factory()->rejected()->create();

    $action = resolve(UpdateCommitStatuses::class);

    $action->handle([$pending->id, $rejected->id], CommitStatus::Approved, onlyPending: true);

    expect($pending->refresh()->status)->toBe(CommitStatus::Approved)
        ->and($rejected->refresh()->status)->toBe(CommitStatus::Rejected);
});

it('notifies the parent authors of the approved replies', function (): void {
    $parent = Commit::factory()->create();
    $replies = Commit::factory()->pending()->for($parent, 'parent')->count(2)->create();
    $rejected = Commit::factory()->rejected()->for($parent, 'parent')->create();

    $action = resolve(UpdateCommitStatuses::class);

    $action->handle([...$replies->modelKeys(), $rejected->id], CommitStatus::Approved, onlyPending: true);

    expect($parent->user->notifications()->pluck('commit_id')->all())
        ->toEqualCanonicalizing($replies->modelKeys());
});

it('notifies the quoted authors of the approved quotes', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    $quoted = Commit::factory()->create();
    $quotes = Commit::factory()->pending()->for($quoted, 'quotedCommit')->count(2)->create();
    $rejected = Commit::factory()->rejected()->for($quoted, 'quotedCommit')->create();

    $action = resolve(UpdateCommitStatuses::class);

    $action->handle([...$quotes->modelKeys(), $rejected->id], CommitStatus::Approved, onlyPending: true);

    expect($quoted->user->notifications()->pluck('commit_id')->all())
        ->toEqualCanonicalizing($quotes->modelKeys());
});

it('lifts the thread when a reply is approved', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->pending()->for($commit, 'parent')->create();

    resolve(UpdateCommitStatuses::class)->handle([$reply->id], CommitStatus::Approved);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, new ThreadEngagement(stargazers: 0, quoters: 0, repliers: 1, repliersAnsweredByAuthor: 0)), 1e-9);
});

it('lifts the quoted thread when a quote is approved', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    $commit = Commit::factory()->create();
    $quote = Commit::factory()->pending()->for($commit, 'quotedCommit')->create();

    resolve(UpdateCommitStatuses::class)->handle([$quote->id], CommitStatus::Approved);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, new ThreadEngagement(stargazers: 0, quoters: 1, repliers: 0, repliersAnsweredByAuthor: 0)), 1e-9);
});

it('queues undecided roots on approval but not replies empty posts or completed decisions', function (): void {
    Queue::fake([ClassifyCommitTopics::class, CheckUserLevel::class]);
    $unknown = Commit::factory()->pending()->create();
    $interesting = Commit::factory()->pending()->create(['topic_decision' => true]);
    $reply = Commit::factory()->pending()->create(['parent_id' => $unknown->id]);
    $empty = Commit::factory()->pending()->create(['body' => '']);
    $negative = Commit::factory()->pending()->create(['topic_decision' => false]);

    resolve(UpdateCommitStatuses::class)->handle([$unknown->id, $interesting->id, $reply->id, $empty->id, $negative->id], CommitStatus::Approved);

    Queue::assertPushed(ClassifyCommitTopics::class, 1);
    Queue::assertPushed(ClassifyCommitTopics::class, fn (ClassifyCommitTopics $job): bool => $job->commitIds === [$unknown->id]);
});
