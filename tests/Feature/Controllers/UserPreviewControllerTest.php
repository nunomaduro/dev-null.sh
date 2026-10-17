<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('returns a summary of the user for the user preview', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat', 'bio' => 'Hello']);

    $user->followers()->attach($viewer);
    $user->following()->attach([$viewer->id, User::factory()->create()->id]);

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->pending()->create();

    $response = $this->actingAs($viewer)
        ->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'The Octocat')
        ->assertJsonPath('user.bio', 'Hello')
        ->assertJsonPath('user.commits_count', 2)
        ->assertJsonPath('user.followers_count', 1)
        ->assertJsonPath('user.following_count', 2)
        ->assertJsonPath('isFollowing', true)
        ->assertJsonPath('followsYou', true)
        ->assertJsonMissingPath('user.email')
        ->assertJsonMissingPath('user.github_id');
});

it('does not count deleted commits in the user preview', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create()->delete();

    $response = $this->actingAs($user)
        ->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('user.commits_count', 1);
});

it('returns a preview for the current user', function (): void {
    $user = User::factory()->create(['username' => 'shipper', 'name' => 'Shipper']);

    $response = $this->actingAs($user)
        ->getJson('/@shipper/preview');

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'Shipper')
        ->assertJsonPath('isFollowing', false)
        ->assertJsonPath('followsYou', false);
});

it('loads counts without joining users', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create(['username' => 'octocat']);

    DB::enableQueryLog();

    $this->actingAs($viewer)->getJson('/@octocat/preview');

    $queries = array_column(DB::getQueryLog(), 'query');

    expect($queries)->each->not->toContain('join');
});

it('reports when neither user follows the other', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('isFollowing', false)
        ->assertJsonPath('followsYou', false);
});

it('reports whether the viewer mutes or blocks the user', function (string $relation, bool $isMuting, bool $isBlocking): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create(['username' => 'octocat']);

    $viewer->{$relation}()->attach($user);

    $response = $this->actingAs($viewer)
        ->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('isMuting', $isMuting)
        ->assertJsonPath('isBlocking', $isBlocking)
        ->assertJsonPath('isUnavailable', $isBlocking);
})->with([
    'muting' => ['muting', true, false],
    'blocking' => ['blocking', false, true],
]);

it('shows a neutral unavailable preview to someone the user blocked', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create(['username' => 'octocat']);

    $user->blocking()->attach($viewer);

    $response = $this->actingAs($viewer)
        ->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('isUnavailable', true)
        ->assertJsonPath('isBlocking', false)
        ->assertJsonPath('isMuting', false)
        ->assertJsonMissingPath('user.commits_count')
        ->assertJsonMissingPath('user.followers_count');
});

it('returns not found for unknown usernames', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/@missing/preview');

    $response->assertNotFound();
});

it('returns guests a summary of the user with only approved commits counted', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create();

    $response = $this->getJson('/@octocat/preview');

    $response->assertOk()
        ->assertJsonPath('user.commits_count', 1)
        ->assertJsonPath('isFollowing', false)
        ->assertJsonPath('followsYou', false)
        ->assertJsonPath('isMuting', false)
        ->assertJsonPath('isBlocking', false)
        ->assertJsonPath('isUnavailable', false);
});
