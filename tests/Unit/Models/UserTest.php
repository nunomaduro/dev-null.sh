<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    $user = User::factory()->create()->refresh();

    expect(array_keys($user->toArray()))
        ->toBe([
            'id',
            'username',
            'name',
            'avatar_url',
            'bio',
            'location',
            'website',
            'company',
            'created_at',
            'updated_at',
            'has_sponsors_listing',
            'timezone',
            'lane',
            'has_github_account',
        ]);
});

it('knows whether it has onboarded', function (): void {
    expect(User::factory()->create()->isOnboarded())->toBeTrue()
        ->and(User::factory()->notOnboarded()->create()->isOnboarded())->toBeFalse();
});

it('has commits', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->create();

    expect($user->commits)->toHaveCount(2);
});

it('has ssh keys', function (): void {
    $user = User::factory()->create();
    SshKey::factory()->for($user)->count(2)->create();
    SshKey::factory()->create();

    expect($user->sshKeys)->toHaveCount(2);
});

it('has following and followers', function (): void {
    $user = User::factory()->create();
    $followed = User::factory()->create();
    $follower = User::factory()->create();

    $user->following()->attach($followed);
    $user->followers()->attach($follower);

    expect($user->following->modelKeys())->toBe([$followed->id])
        ->and($user->followers->modelKeys())->toBe([$follower->id])
        ->and($followed->followers->modelKeys())->toBe([$user->id]);
});

it('loads commit and follow counts', function (): void {
    $user = User::factory()->has(Commit::factory()->count(2))->create();
    $user->following()->attach(User::factory()->count(3)->create());
    User::factory()->create()->following()->attach($user);
    Commit::factory()->create();

    $user->loadCounts();

    expect($user->commits_count)->toBe(2)
        ->and($user->following_count)->toBe(3)
        ->and($user->followers_count)->toBe(1)
        ->and($user->isDirty())->toBeFalse();
});

it('loads commit counts visible to the viewer', function (): void {
    $user = User::factory()->has(Commit::factory())->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    expect($user->loadCounts(User::factory()->create())->commits_count)->toBe(1)
        ->and($user->loadCounts()->commits_count)->toBe(3);
});

it('does not count deleted commits', function (): void {
    $user = User::factory()->has(Commit::factory())->create();
    Commit::factory()->for($user)->create()->delete();

    expect($user->loadCounts()->commits_count)->toBe(1)
        ->and($user->loadPublicCounts()->commits_count)->toBe(1);
});

it('loads public counts with approved commits only', function (): void {
    $user = User::factory()->has(Commit::factory())->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();
    $user->following()->attach(User::factory()->count(2)->create());
    User::factory()->create()->following()->attach($user);

    $user->loadPublicCounts();

    expect($user->commits_count)->toBe(1)
        ->and($user->following_count)->toBe(2)
        ->and($user->followers_count)->toBe(1)
        ->and($user->isDirty())->toBeFalse();
});

it('loads counts without joining users', function (): void {
    $user = User::factory()->create();

    DB::enableQueryLog();

    $user->loadCounts();

    $queries = array_column(DB::getQueryLog(), 'query');

    expect($queries)->toHaveCount(3)
        ->each->not->toContain('join');
});

it('knows which commits it has starred', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    expect($user->hasStarred($commit))->toBeFalse();

    $user->starredCommits()->attach($commit);

    expect($user->hasStarred($commit))->toBeTrue()
        ->and($commit->stars->modelKeys())->toBe([$user->id]);

    $user->delete();

    expect($commit->stars()->count())->toBe(0);
});

it('knows if it is following a user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->isFollowing($other))->toBeFalse();

    $user->following()->attach($other);

    expect($user->isFollowing($other))->toBeTrue()
        ->and($other->isFollowing($user))->toBeFalse();
});

it('has no password', function (): void {
    $user = User::factory()->create();

    expect($user->getAuthPassword())->toBe('');
});

it('knows if it is an admin', function (): void {
    config(['app.admins' => ['NunoMaduro']]);

    expect(User::factory()->create(['username' => 'nunomaduro'])->isAdmin())->toBeTrue()
        ->and(User::factory()->create(['username' => 'octocat'])->isAdmin())->toBeFalse();
});

it('has the same lane as the frontend', function (string $id, int $lane): void {
    expect(User::factory()->make(['id' => $id])->lane())->toBe($lane);
})->with([
    ['ff6e0733-5f5a-4d58-a321-4da554c0d799', 0],
    ['5be701c9-deb7-4948-b80a-a1e0d1f0fee9', 1],
    ['edfd235d-6874-4a82-a1ba-4f40c5f40510', 2],
    ['3b4571bc-5802-4005-afc1-2d9aa826b5d3', 3],
    ['3a788e93-4cf7-4819-9377-94be2f329563', 4],
    ['bb5da83f-f9cf-46f2-b66e-51908e44dd14', 5],
]);

it('uses the chosen lane over the automatic one', function (): void {
    $user = User::factory()->make(['id' => 'ff6e0733-5f5a-4d58-a321-4da554c0d799', 'preferred_lane' => 4]);

    expect($user->automaticLane())->toBe(0)
        ->and($user->lane())->toBe(4);
});

it('uses the custom color over any lane', function (): void {
    $user = User::factory()->make(['preferred_lane' => 4, 'lane_color' => '#1a2b3c']);

    expect($user->lane())->toBe('#1a2b3c');
});

it('serializes the effective lane', function (?int $preferredLane, ?string $color, int|string $lane): void {
    $user = User::factory()->create(['id' => '3b4571bc-5802-4005-afc1-2d9aa826b5d3', 'preferred_lane' => $preferredLane, 'lane_color' => $color])->refresh();

    expect($user->toArray())
        ->toHaveKey('lane', $lane)
        ->not->toHaveKey('preferred_lane')
        ->not->toHaveKey('lane_color');
})->with([
    'automatic' => [null, null, 3],
    'chosen' => [0, null, 0],
    'custom' => [null, '#1a2b3c', '#1a2b3c'],
]);

it('knows who it mutes and who mutes it', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->isMuting($other))->toBeFalse();

    $user->muting()->attach($other);

    expect($user->isMuting($other))->toBeTrue()
        ->and($other->isMuting($user))->toBeFalse()
        ->and($user->muting->modelKeys())->toBe([$other->id])
        ->and($other->mutedBy->modelKeys())->toBe([$user->id]);
});

it('knows who it blocks and who blocks it', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $stranger = User::factory()->create();

    expect($user->isBlocking($other))->toBeFalse()
        ->and($user->hasBlockWith($other))->toBeFalse();

    $user->blocking()->attach($other);

    expect($user->isBlocking($other))->toBeTrue()
        ->and($other->isBlocking($user))->toBeFalse()
        ->and($user->hasBlockWith($other))->toBeTrue()
        ->and($other->hasBlockWith($user))->toBeTrue()
        ->and($user->hasBlockWith($stranger))->toBeFalse()
        ->and($user->blocking->modelKeys())->toBe([$other->id])
        ->and($other->blockers->modelKeys())->toBe([$user->id]);
});

it('stores the giphy key encrypted and reads it back', function (): void {
    $user = User::factory()->withGiphyKey('giphy-test-key')->create();

    expect($user->refresh()->giphy_key)->toBe('giphy-test-key')
        ->and(DB::table('users')->where('id', $user->id)->value('giphy_key'))
        ->toBeString()
        ->not->toBe('giphy-test-key')
        ->not->toContain('giphy-test-key');
});

it('defaults the giphy key to null', function (): void {
    expect(User::factory()->create()->refresh()->giphy_key)->toBeNull();
});

it('serializes no giphy key', function (): void {
    $user = User::factory()->withGiphyKey()->create();

    expect($user->toArray())->not->toHaveKey('giphy_key')
        ->and($user->jsonSerialize())->not->toHaveKey('giphy_key');
});
