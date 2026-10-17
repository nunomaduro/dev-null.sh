<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it("lists a user's followers", function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $follower = User::factory()->create();
    $user->followers()->attach($follower);
    $user->following()->attach(User::factory()->create());

    $response = $this->actingAs(User::factory()->create())
        ->get(route('user-followers.index', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/connections')
            ->where('type', 'followers')
            ->has('users.data', 1)
            ->where('users.data.0.id', $follower->id)
            ->missing('users.data.0.email'));
});

it('marks the users the viewer follows', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $notFollowed = User::factory()->create();
    $followed = User::factory()->create();
    $user->followers()->attach([$notFollowed->id, $followed->id]);
    $viewer->following()->attach($followed);

    $response = $this->actingAs($viewer)
        ->get(route('user-followers.index', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 2)
            ->where('users.data.0.id', $followed->id)
            ->where('users.data.0.is_followed', true)
            ->where('users.data.1.id', $notFollowed->id)
            ->where('users.data.1.is_followed', false));
});

it('looks missing to people across a block', function (bool $viewerBlocks): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $user->followers()->attach(User::factory()->create());

    $viewerBlocks ? $viewer->blocking()->attach($user) : $user->blocking()->attach($viewer);

    $response = $this->actingAs($viewer)
        ->get(route('user-followers.index', $user));

    $response->assertNotFound();
})->with([true, false]);

it('lists them to guests, without anyone marked as followed', function (): void {
    $user = User::factory()->create();
    $user->followers()->attach(User::factory()->create());

    $response = $this->get(route('user-followers.index', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/connections')
            ->has('users.data', 1)
            ->where('users.data.0.is_followed', false));
});

it('returns guests to the followers once they sign in', function (): void {
    $user = User::factory()->create();

    $this->get(route('user-followers.index', $user))->assertOk();

    expect(session('url.intended'))->toBe(route('user-followers.index', $user));
});

it('previews the list to guests without letting search engines index it', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat']);

    $this->get(route('user-followers.index', $user))
        ->assertOk()
        ->assertSee('<title>People following The Octocat (@octocat) on /dev/null</title>', false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('<link rel="canonical" href="'.route('user-followers.index', $user).'">', false)
        ->assertSee('<meta property="og:image" content="'.route('user-open-graph-image.show', $user).'?v=', false);
});

it('does not count the user for guests loading more of the list', function (): void {
    $user = User::factory()->create();
    $user->followers()->attach(User::factory()->create());

    DB::enableQueryLog();

    $this->get(route('user-followers.index', $user), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'user/connections',
        'X-Inertia-Partial-Data' => 'users',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonMissingPath('props.user');

    expect(collect(DB::getQueryLog())->pluck('query')->all())
        ->not->toBeEmpty()
        ->each->not->toContain('count(*)');
});

it('keeps the default link preview of the list for signed-in users', function (): void {
    $user = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('user-followers.index', $user))
        ->assertOk()
        ->assertDontSee('<meta name="robots"', false)
        ->assertDontSee('og:url', false);
});
