<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\SuggestedUsersQuery;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('suggests people to follow, with why and whether they follow the user', function (): void {
    $friend = User::factory()->create();
    $vouched = User::factory()->has(Commit::factory())->create(['username' => 'octocat']);

    $this->user->following()->attach($friend);
    $friend->following()->attach($vouched);
    $vouched->following()->attach($this->user);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vouched->id)
        ->assertJsonPath('data.0.username', 'octocat')
        ->assertJsonPath('data.0.lane', $vouched->lane())
        ->assertJsonPath('data.0.followed_by_following_count', 1)
        ->assertJsonPath('data.0.followers_count', 1)
        ->assertJsonPath('data.0.follows_you', true)
        ->assertJsonPath('data.0.is_followed', false);
});

it('suggests popular people nobody the user follows follows, with their followers', function (): void {
    $popular = User::factory()->has(Commit::factory())->create();

    $popular->followers()->attach(User::factory()->count(2)->create());

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $popular->id)
        ->assertJsonPath('data.0.followed_by_following_count', 0)
        ->assertJsonPath('data.0.followers_count', 2)
        ->assertJsonPath('data.0.follows_you', false)
        ->assertJsonPath('data.0.is_followed', false);
});

it('sends three suggestions and three spares, as the web does', function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

it("moves to the next page of the day's picks each round", function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))->json('data.*.id');
    $again = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 0]))->json('data.*.id');
    $next = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 1]))->json('data.*.id');

    expect($again)->toBe($first)
        ->and(array_slice($next, 0, 3))->not->toBe(array_slice($first, 0, 3));
});

it('leaves out people the user dismissed', function (): void {
    $dismissed = User::factory()->has(Commit::factory())->create();
    $suggested = User::factory()->has(Commit::factory())->create();

    $this->user->dismissedSuggestions()->attach($dismissed);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$suggested->id]);
});

it('starts at the first round when none is given', function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 0]))->json('data.*.id');

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => '']))
        ->assertOk()
        ->assertJsonPath('data.*.id', $first);
});

it('leaves out people the user mutes or has a block with', function (Closure $hide): void {
    $hidden = User::factory()->has(Commit::factory())->create();
    $suggested = User::factory()->has(Commit::factory())->create();

    $hide($this->user, $hidden);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$suggested->id]);
})->with([
    'muting' => fn (User $user, User $hidden) => $user->muting()->attach($hidden),
    'blocking' => fn (User $user, User $hidden) => $user->blocking()->attach($hidden),
    'blocked' => fn (User $user, User $hidden) => $hidden->blocking()->attach($user),
]);

it('suggests the people a profile follows first, as the web does beside it', function (): void {
    $profile = User::factory()->has(Commit::factory())->create();
    $followed = User::factory()->has(Commit::factory())->create();
    $regular = User::factory()->has(Commit::factory())->create();

    $profile->following()->attach($followed);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['username' => $profile->username]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$followed->id, $regular->id]);
});

it("suggests a thread's other participants first, from any commit in it, as the web does beside it", function (): void {
    $author = User::factory()->create();
    $root = Commit::factory()->for($author)->create();
    $participant = User::factory()->has(Commit::factory())->create();
    $regular = User::factory()->has(Commit::factory())->create();
    $reply = Commit::factory()->for($participant)->for($root, 'parent')->create(['root_id' => $root->id]);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['commit_id' => $root->id]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$participant->id, $regular->id])
        ->assertJsonPath('data.0.followed_by_following_count', 0)
        ->assertJsonPath('data.0.is_followed', false);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['commit_id' => $reply->id]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$author->id, $regular->id]);
});

it('suggests what the web suggests on the same profile or commit page', function (string $page): void {
    $author = User::factory()->has(Commit::factory())->create();
    $root = Commit::factory()->for($author)->create();
    $people = User::factory()->count(12)->has(Commit::factory())->create();

    $author->following()->attach($people->take(4));
    foreach ($people->slice(4, 4) as $participant) {
        Commit::factory()->for($participant)->for($root, 'parent')->create(['root_id' => $root->id]);
    }

    $people->last()->followers()->attach($people->take(3));

    [$webRoute, $context] = $page === 'profile'
        ? [route('user.show', $author), ['username' => $author->username]]
        : [route('commit.show', [$author, $root]), ['commit_id' => $root->id]];

    $web = null;
    $this->actingAs($this->user)->get($webRoute)
        ->assertOk()
        ->assertInertia(function ($inertia) use (&$web) {
            return $inertia->loadDeferredProps(function ($reload) use (&$web) {
                return $reload->where('suggestedUsers', function ($users) use (&$web): bool {
                    $web = collect($users)->pluck('id')->all();

                    return true;
                });
            });
        });

    $contextual = ($page === 'profile' ? $people->take(4) : $people->slice(4, 4))->pluck('id')->all();

    expect($web)->toHaveCount(6)
        ->and(array_diff(array_slice($web, 0, 3), $contextual))->toBe([]);

    foreach ([0, 1, 2] as $round) {
        $api = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', [...$context, 'round' => $round]))
            ->assertOk()
            ->json('data.*.id');

        if ($round === 0) {
            expect($api)->toBe($web);
        }

        expect($api)->toBe(resolve(SuggestedUsersQuery::class)->get($this->user, round: $round, spares: 3, context: $page === 'profile' ? $author : $root)->pluck('id')->all());
    }
})->with(['profile', 'commit']);

it('falls back to the regular suggestions beside a profile across a block, as the web does', function (Closure $block): void {
    $profile = User::factory()->has(Commit::factory())->create();
    $followed = User::factory()->has(Commit::factory())->create();
    User::factory()->count(5)->has(Commit::factory())->create();

    $profile->following()->attach($followed);
    $block($this->user, $profile);

    $regular = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))->json('data.*.id');

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['username' => $profile->username]))
        ->assertOk()
        ->assertJsonPath('data.*.id', $regular);
})->with([
    'blocking' => fn (User $user, User $profile) => $user->blocking()->attach($profile),
    'blocked' => fn (User $user, User $profile) => $profile->blocking()->attach($user),
]);

it("takes the user's own pending commit as the page, as its page shows it to them", function (): void {
    $root = Commit::factory()->for($this->user)->pending()->create();
    $participant = User::factory()->has(Commit::factory())->create();

    Commit::factory()->for($participant)->for($root, 'parent')->create(['root_id' => $root->id]);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['commit_id' => $root->id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $participant->id);
});

it('refuses a commit whose page the user cannot see', function (Closure $commit): void {
    $participant = User::factory()->has(Commit::factory())->create();
    $root = $commit($this->user);

    Commit::factory()->for($participant)->for($root, 'parent')->create(['root_id' => $root->id]);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['commit_id' => $root->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['commit_id' => 'That commit is no longer available.']);
})->with([
    "someone else's pending commit" => fn (User $user) => Commit::factory()->pending()->create(),
    "someone else's rejected commit" => fn (User $user) => Commit::factory()->rejected()->create(),
    'a commit by someone the user blocks' => function (User $user): Commit {
        $commit = Commit::factory()->create();
        $user->blocking()->attach($commit->user);

        return $commit;
    },
    'a commit by someone who blocks the user' => function (User $user): Commit {
        $commit = Commit::factory()->create();
        $commit->user->blocking()->attach($user);

        return $commit;
    },
    'a deleted commit' => function (User $user): Commit {
        $commit = Commit::factory()->create();
        $commit->delete();

        return $commit;
    },
]);

it('refuses a page that does not exist or is not one page', function (array $query, string $field): void {
    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'an unknown username' => fn (): array => [['username' => 'nobody-here'], 'username'],
    'an unknown commit' => fn (): array => [['commit_id' => '01a16c57-e371-73fb-a4d7-6ad455c9dd57'], 'commit_id'],
    'a commit id that is not one' => fn (): array => [['commit_id' => 'latest'], 'commit_id'],
    'a profile and a commit' => fn (): array => [['username' => User::factory()->create()->username, 'commit_id' => Commit::factory()->create()->id], 'commit_id'],
]);

it('refuses a round that is not a whole number from zero', function (mixed $round): void {
    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => $round]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('round');
})->with([-1, 'next']);

it('requires a signed request', function (): void {
    $this->getJson(route('api-suggested-user.index'))->assertUnauthorized();
});
