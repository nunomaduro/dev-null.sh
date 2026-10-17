<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('keeps id projections lightweight when poll eager loading is disabled', function (): void {
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $viewer = User::factory()->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $commit = Commit::query()->withPollFor($viewer)->setEagerLoads([])->select('id')->findOrFail($poll->commit_id);

    expect($commit->getAttributes())->toBe(['id' => $poll->commit_id])
        ->and($commit->relationLoaded('poll'))->toBeFalse()
        ->and(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

it('loads commits with every column but the embedding, which stays stored', function (): void {
    $commit = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    $columns = array_values(array_diff(Schema::getColumnListing('commits'), ['embedding']));

    expect(array_keys(Commit::query()->findOrFail($commit->id)->getAttributes()))->toBe($columns)
        ->and(array_keys(Commit::query()->withStarsFor(null)->findOrFail($commit->id)->getAttributes()))->toBe([...$columns, 'stars_count', 'starred'])
        ->and(Commit::query()->whereKey($commit->id)->value('embedding'))->toBe(embeddingVector(0));
});

it('scopes commits to those visible to a user', function (): void {
    $user = User::factory()->create();

    $approved = Commit::factory()->create();
    $ownPending = Commit::factory()->for($user)->pending()->create();
    $ownRejected = Commit::factory()->for($user)->rejected()->create();
    Commit::factory()->pending()->create();
    Commit::factory()->rejected()->create();

    expect(Commit::query()->visibleTo($user)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$approved->id, $ownPending->id, $ownRejected->id])->sort()->values()->all());
});

it('scopes commits to the approved ones for guests', function (): void {
    $approved = Commit::factory()->create();
    Commit::factory()->pending()->create();
    Commit::factory()->rejected()->create();

    expect(Commit::query()->visibleTo(null)->pluck('id')->all())->toBe([$approved->id]);
});

it('loads the star count and whether the viewer starred', function (): void {
    $viewer = User::factory()->create();
    $starred = Commit::factory()->create();
    $plain = Commit::factory()->create();

    $viewer->starredCommits()->attach($starred);
    User::factory()->create()->starredCommits()->attach($starred);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $commits = Commit::query()->withStarsFor($viewer)->with('user')->get()->keyBy('id');

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($commits[$starred->id]->stars_count)->toBe(2)
        ->and($commits[$starred->id]->starred)->toBeTrue()
        ->and($commits[$plain->id]->stars_count)->toBe(0)
        ->and($commits[$plain->id]->starred)->toBeFalse();
});

it('scopes commits to pending ones', function (): void {
    $pending = Commit::factory()->pending()->create();
    Commit::factory()->create();
    Commit::factory()->rejected()->create();

    expect(Commit::query()->pending()->sole()->is($pending))->toBeTrue();
});

it('scopes commits to top-level ones', function (): void {
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->create();

    expect(Commit::query()->topLevel()->pluck('id')->all())->toBe([$parent->id]);
});

it('scopes thread commits to visible ones and deleted ones that still have visible replies', function (): void {
    $user = User::factory()->create();

    $commit = Commit::factory()->create();
    $tombstone = Commit::factory()->create();
    Commit::factory()->for($tombstone, 'parent')->create();
    $tombstone->delete();
    $deletedChain = Commit::factory()->create();
    $deletedReply = Commit::factory()->for($deletedChain, 'parent')->create();
    Commit::factory()->for($deletedReply, 'parent')->create();
    $deletedReply->delete();
    $deletedChain->delete();
    $onlyHiddenReplies = Commit::factory()->create();
    Commit::factory()->for($onlyHiddenReplies, 'parent')->pending()->create();
    $onlyHiddenReplies->delete();
    Commit::factory()->create()->delete();
    Commit::factory()->pending()->create();

    $ids = Commit::query()->topLevel()->visibleInThreadTo($user)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$commit->id, $tombstone->id, $deletedChain->id])->sort()->values()->all());
});

it('scopes commits to approved ones', function (): void {
    $user = User::factory()->create();
    $approved = Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    expect(Commit::query()->approved()->sole()->is($approved))->toBeTrue();
});

it('hides commits across a block in either direction', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();
    $blocker = User::factory()->create();

    $user->blocking()->attach($blocked);
    $blocker->blocking()->attach($user);

    $own = Commit::factory()->for($user)->create();
    $visible = Commit::factory()->create();
    Commit::factory()->for($blocked)->create();
    Commit::factory()->for($blocker)->create();

    expect(Commit::query()->visibleTo($user)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$own->id, $visible->id])->sort()->values()->all())
        ->and(Commit::query()->visibleTo($blocked)->whereBelongsTo($user)->exists())->toBeFalse()
        ->and(Commit::query()->visibleTo($blocker)->whereBelongsTo($user)->exists())->toBeFalse();
});

it('scopes commits to those by people the user has not muted', function (): void {
    $user = User::factory()->create();
    $muted = User::factory()->create();

    $user->muting()->attach($muted);

    $visible = Commit::factory()->create();
    Commit::factory()->for($muted)->create();

    expect(Commit::query()->withoutMutedAuthorsFor($user)->pluck('id')->all())->toBe([$visible->id])
        ->and(Commit::query()->withoutMutedAuthorsFor($muted)->count())->toBe(2);
});

it('leaves muted and blocked replies out of threads and reply counts', function (): void {
    $user = User::factory()->create();
    $muted = User::factory()->create();
    $blocked = User::factory()->create();

    $user->muting()->attach($muted);
    $user->blocking()->attach($blocked);

    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->for($muted)->create();
    Commit::factory()->for($commit, 'parent')->for($blocked)->create();

    $deletedWithMutedReply = Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($deletedWithMutedReply, 'parent')->for($muted)->create();
    $deletedWithMutedReply->delete();

    expect($commit->replies()->visibleInThreadTo($user)->pluck('id')->all())->toBe([$reply->id])
        ->and(Commit::query()->withVisibleReplyCount($user)->find($commit->id)->replies_count)->toBe(1);
});
