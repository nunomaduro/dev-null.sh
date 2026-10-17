<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\UserStarsCountQuery;

it('has no stars without starred commits', function (): void {
    $user = User::factory()->has(Commit::factory())->create();

    expect(resolve(UserStarsCountQuery::class)->get($user, null))->toBe(0);
});

it('counts the stars across every commit and reply of the user', function (): void {
    $user = User::factory()->create();

    $commit = Commit::factory()->for($user)->create();
    $reply = Commit::factory()->for($user)->for(Commit::factory(), 'parent')->create();

    User::factory()->count(2)->create()->each(fn (User $stargazer) => $stargazer->starredCommits()->attach($commit));
    User::factory()->create()->starredCommits()->attach($reply);
    User::factory()->create()->starredCommits()->attach(Commit::factory()->create());

    expect(resolve(UserStarsCountQuery::class)->get($user, null))->toBe(3);
});

it('only counts the stars of commits the viewer may see', function (): void {
    $user = User::factory()->create();
    $stargazer = User::factory()->create();

    $stargazer->starredCommits()->attach([
        Commit::factory()->for($user)->create()->id,
        Commit::factory()->for($user)->pending()->create()->id,
        Commit::factory()->for($user)->rejected()->create()->id,
    ]);

    expect(resolve(UserStarsCountQuery::class))
        ->get($user, null)->toBe(1)
        ->get($user, $stargazer)->toBe(1)
        ->get($user, $user)->toBe(3);
});

it('does not count the stars of deleted commits', function (): void {
    $user = User::factory()->create();

    $commit = Commit::factory()->for($user)->create();
    User::factory()->create()->starredCommits()->attach($commit);

    $commit->delete();

    expect(resolve(UserStarsCountQuery::class)->get($user, $user))->toBe(0);
});

it('does not count stars the user gave their own commits', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    $user->starredCommits()->attach($commit);
    User::factory()->create()->starredCommits()->attach($commit);

    expect(resolve(UserStarsCountQuery::class)->get($user, $user))->toBe(1);
});
