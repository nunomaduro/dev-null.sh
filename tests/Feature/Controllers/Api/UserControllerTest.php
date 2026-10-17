<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\User;
use App\Queries\UserAchievementsQuery;
use App\Queries\UserActivityQuery;

it('shows a public profile with public counts', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
        'bio' => 'I am the Octocat.',
        'company' => '@github',
        'location' => 'San Francisco',
        'website' => 'https://github.blog',
    ]);

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();
    Commit::factory()->for($user)->create()->delete();

    $user->followers()->attach(User::factory()->count(3)->create());
    $user->following()->attach(User::factory()->create());

    $response = $this->getJson(route('api-user.show', $user));

    $response->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'username' => 'octocat',
            'name' => 'The Octocat',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
            'bio' => 'I am the Octocat.',
            'company' => '@github',
            'location' => 'San Francisco',
            'website' => 'https://github.blog',
            'github_url' => 'https://github.com/octocat',
            'html_url' => route('user.show', $user),
            'feed_url' => route('user-feed.show', $user),
            'created_at' => now()->toIso8601ZuluString(),
            'commits_count' => 2,
            'followers_count' => 3,
            'following_count' => 1,
        ]]);
});

it('never exposes hidden fields', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'email' => 'octocat@github.com',
        'github_id' => '583231',
        'preferred_lane' => 3,
    ]);

    $this->getJson('/api/v1/users/octocat')
        ->assertOk()
        ->assertDontSee('octocat@github.com')
        ->assertDontSee('583231')
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.github_id')
        ->assertJsonMissingPath('data.preferred_lane')
        ->assertJsonMissingPath('data.lane')
        ->assertJsonMissingPath('data.remember_token');
});

it('returns not found for unknown usernames', function (): void {
    $this->getJson('/api/v1/users/missing')->assertNotFound();
});

it('shows a signed request the profile as the web shows it to its user', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
        'bio' => 'I am the Octocat.',
        'company' => '@github',
        'location' => 'San Francisco',
        'website' => 'https://github.blog',
    ]);

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => now()->subDays(3)]);
    Commit::factory()->for($user)->pending()->create();

    $user->followers()->attach($viewer);
    $user->following()->attach(User::factory()->count(2)->create());

    $response = signedApiRequest($keyPair, 'GET', route('api-user.show', $user));

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'username' => 'octocat',
                'name' => 'The Octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
                'bio' => 'I am the Octocat.',
                'company' => '@github',
                'location' => 'San Francisco',
                'website' => 'https://github.blog',
                'github_url' => 'https://github.com/octocat',
                'html_url' => route('user.show', $user),
                'feed_url' => route('user-feed.show', $user),
                'created_at' => now()->toIso8601ZuluString(),
                'commits_count' => 3,
                'followers_count' => 1,
                'following_count' => 2,
                'lane' => $user->lane(),
            ],
            'is_following' => true,
            'follows_you' => false,
            'is_muting' => false,
            'activity' => resolve(UserActivityQuery::class)->get($user, $viewer),
            'streaks' => ['longest' => 1, 'current' => 1],
            'achievements' => resolve(UserAchievementsQuery::class)->get($user, $viewer),
            'pinned_commit' => null,
        ])
        ->assertJsonPath('achievements.rank', 'user')
        ->assertJsonPath('achievements.level', 1)
        ->assertJsonPath('activity.scale', 'week')
        ->assertJsonCount(7, 'activity.buckets')
        ->assertJsonPath('activity.buckets.3', ['date' => '2026-10-12', 'count' => 1])
        ->assertJsonPath('activity.buckets.6', ['date' => '2026-10-15', 'count' => 2]);
});

it('counts the pending commits of a signed-in user on their own profile', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    Commit::factory()->for($user)->pending()->create();

    $this->getJson(route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('data.commits_count', 0)
        ->assertJsonMissingPath('is_following')
        ->assertJsonMissingPath('follows_you');

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('data.commits_count', 1)
        ->assertJsonPath('is_following', false);
});

it('tells a signed request whether the profile follows its user', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();

    $user->following()->attach($viewer);

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('is_following', false)
        ->assertJsonPath('follows_you', true);
});

it('tells a signed request whether its user mutes the profile, which still shows', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    $viewer->muting()->attach($user);

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('is_muting', true);

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', $user))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$commit->id]);
});

it('shows a signed request the achievements and unix rank of the profile', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(60)->create();

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('achievements', resolve(UserAchievementsQuery::class)->get($user, $viewer))
        ->assertJsonPath('achievements.rank', 'maintainer')
        ->assertJsonPath('achievements.level', 4)
        ->assertJsonCount(6, 'achievements.ranks');

    $this->getJson(route('api-user.show', $user))
        ->assertOk()
        ->assertJsonMissingPath('achievements');
});

it('shows a signed request the pinned commit of the profile', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create(['body' => 'Pinned']);
    $reply = Commit::factory()->for($pinned, 'parent')->create();
    Commit::factory()->for($pinned, 'parent')->pending()->create();
    CommitImage::factory()->for($pinned)->create();
    $user->forceFill(['pinned_commit_id' => $pinned->id])->save();

    $response = signedApiRequest($keyPair, 'GET', route('api-user.show', $user));

    $response->assertOk()
        ->assertJsonPath('pinned_commit.id', $pinned->id)
        ->assertJsonPath('pinned_commit.body', 'Pinned')
        ->assertJsonPath('pinned_commit.is_pinned', true)
        ->assertJsonPath('pinned_commit.replies_count', 1)
        ->assertJsonCount(1, 'pinned_commit.images')
        ->assertJsonPath('pinned_commit.author.id', $user->id)
        ->assertJsonPath('pinned_commit.reply_preview.data.*.id', [$reply->id]);
});

it('hides a pinned commit the signed-in user may not see', function (Closure $pinned): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create();
    $user->forceFill(['pinned_commit_id' => $pinned($user)->id])->save();

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('pinned_commit', null);
})->with([
    'pending' => fn (User $user): Commit => Commit::factory()->for($user)->pending()->create(),
    'deleted' => fn (User $user): Commit => tap(Commit::factory()->for($user)->create())->delete(),
]);

it('shows a signed-in user their own pending pinned commit', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $pinned = Commit::factory()->for($user)->pending()->create();
    $user->forceFill(['pinned_commit_id' => $pinned->id])->save();

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('pinned_commit.id', $pinned->id)
        ->assertJsonPath('pinned_commit.status', 'pending');
});

it('tells a signed request it blocked the profile, as the web does', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create(['name' => 'The Octocat']);

    $viewer->blocking()->attach($user);

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertForbidden()
        ->assertExactJson([
            'message' => "You blocked The Octocat. Neither of you can follow, reply to, or star the other, or see each other's commits.",
            'code' => 'user_blocked',
        ]);

    $this->getJson(route('api-user.show', $user))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('shows a signed request a profile that blocked its user as unavailable, without saying why', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();

    $user->blocking()->attach($viewer);

    signedApiRequest($keyPair, 'GET', route('api-user.show', $user))
        ->assertForbidden()
        ->assertExactJson([
            'message' => "This profile isn't available.",
            'code' => 'user_unavailable',
        ]);
});
