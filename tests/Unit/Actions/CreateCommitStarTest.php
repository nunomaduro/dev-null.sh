<?php

declare(strict_types=1);

use App\Actions\CreateCommitStar;
use App\Models\Commit;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Support\Facades\DB;

it('may star a commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    resolve(CreateCommitStar::class)->handle($user, $commit);

    expect($user->hasStarred($commit))->toBeTrue()
        ->and($commit->stars()->count())->toBeOne();
});

it('is idempotent', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $action = resolve(CreateCommitStar::class);

    $action->handle($user, $commit);
    $action->handle($user, $commit);

    expect($user->starredCommits()->count())->toBeOne();
});

it('keeps when an existing star was given', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $this->travelTo(now()->subDay());
    resolve(CreateCommitStar::class)->handle($user, $commit);
    $this->travelBack();

    resolve(CreateCommitStar::class)->handle($user, $commit);

    expect($user->starredCommits()->sole()->pivot->created_at->isSameDay(now()->subDay()))->toBeTrue();
});

it('stars without reading the stars the user already gave', function (): void {
    $user = User::factory()->create();
    $user->starredCommits()->attach(Commit::factory()->count(2)->create());
    $commit = Commit::factory()->create();

    DB::enableQueryLog();

    resolve(CreateCommitStar::class)->handle($user, $commit);

    expect($user->starredCommits()->count())->toBe(3)
        ->and(collect(DB::getQueryLog())->pluck('query')->all())
        ->each->not->toStartWith('select * from "stars"');
});

it('notifies the author', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    resolve(CreateCommitStar::class)->handle($user, $commit);

    expect($commit->user->notifications()->sole()->actor_id)->toBe($user->id);
});

it('lifts the commit', function (): void {
    $commit = Commit::factory()->create();

    resolve(CreateCommitStar::class)->handle(User::factory()->create(), $commit);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 1, 0), 1e-9);
});
