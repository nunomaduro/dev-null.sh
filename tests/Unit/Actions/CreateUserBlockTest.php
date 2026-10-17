<?php

declare(strict_types=1);

use App\Actions\CreateCommitStar;
use App\Actions\CreateUserBlock;
use App\Models\Commit;
use App\Models\User;
use App\Services\TheAlgorithm;

it('may block a user, removing follows in both directions', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();
    $friend = User::factory()->create();

    $user->following()->attach([$blocked->id, $friend->id]);
    $blocked->following()->attach([$user->id, $friend->id]);

    resolve(CreateUserBlock::class)->handle($user, $blocked);

    expect($user->isBlocking($blocked))->toBeTrue()
        ->and($blocked->isBlocking($user))->toBeFalse()
        ->and($user->isFollowing($blocked))->toBeFalse()
        ->and($blocked->isFollowing($user))->toBeFalse()
        ->and($user->isFollowing($friend))->toBeTrue()
        ->and($blocked->isFollowing($friend))->toBeTrue();
});

it('is idempotent', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();

    $action = resolve(CreateUserBlock::class);

    $action->handle($user, $blocked);
    $action->handle($user, $blocked);

    expect($user->blocking()->count())->toBeOne();
});

it('removes the stars in both directions, with their notifications', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();
    $friend = User::factory()->create();
    $userCommit = Commit::factory()->for($user)->create();
    $blockedCommit = Commit::factory()->for($blocked)->create();
    $friendCommit = Commit::factory()->for($friend)->create();

    resolve(CreateCommitStar::class)->handle($blocked, $userCommit);
    resolve(CreateCommitStar::class)->handle($user, $blockedCommit);
    resolve(CreateCommitStar::class)->handle($friend, $userCommit);
    resolve(CreateCommitStar::class)->handle($user, $friendCommit);
    resolve(CreateCommitStar::class)->handle($blocked, $friendCommit);

    resolve(CreateUserBlock::class)->handle($user, $blocked);

    expect($blocked->hasStarred($userCommit))->toBeFalse()
        ->and($user->hasStarred($blockedCommit))->toBeFalse()
        ->and($friend->hasStarred($userCommit))->toBeTrue()
        ->and($user->hasStarred($friendCommit))->toBeTrue()
        ->and($blocked->hasStarred($friendCommit))->toBeTrue()
        ->and($user->notifications()->sole()->actor_id)->toBe($friend->id)
        ->and($blocked->notifications()->count())->toBe(0)
        ->and($friend->notifications()->count())->toBe(2);
});

it('removes the stars on deleted commits too', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    $blocked->starredCommits()->attach($commit);
    $commit->delete();

    resolve(CreateUserBlock::class)->handle($user, $blocked);

    expect($blocked->starredCommits()->withTrashed()->count())->toBe(0);
});

it('lowers the commits that lost a star back', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    resolve(CreateCommitStar::class)->handle($blocked, $commit);

    resolve(CreateUserBlock::class)->handle($user, $blocked);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 0), 1e-9);
});
