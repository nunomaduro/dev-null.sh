<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('renders a user profile', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'preferred_lane' => 4]);
    $viewer = User::factory()->create();

    $user->followers()->attach($viewer);
    $user->following()->attach(User::factory()->count(2)->create());

    Commit::factory()->for($user)->create(['created_at' => now()->subDay(), 'body' => 'Older']);
    Commit::factory()->for($user)->create(['body' => 'Newer']);
    Commit::factory()->create();

    $response = $this->actingAs($viewer)
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/show')
            ->where('user.id', $user->id)
            ->where('user.username', 'octocat')
            ->where('user.lane', 4)
            ->where('user.commits_count', 2)
            ->where('user.followers_count', 1)
            ->where('user.following_count', 2)
            ->where('user.has_sponsors_listing', false)
            ->missing('user.email')
            ->missing('user.github_id')
            ->where('isFollowing', true)
            ->where('followsYou', false)
            ->has('achievements')
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Newer')
            ->where('commits.data.0.user.username', 'octocat')
            ->where('commits.data.0.stars_count', 0)
            ->where('commits.data.0.starred', false)
            ->where('commits.data.1.body', 'Older'));
});

it('renders whether the user can be sponsored on github', function (): void {
    $user = User::factory()->create(['has_sponsors_listing' => true]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('user.show', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('user.has_sponsors_listing', true));
});

it('does not count the user relations or activity when reloading only commits', function (string $tab): void {
    $user = User::factory()->has(Commit::factory())->create(['username' => 'octocat']);
    Commit::factory()->for($user)->for(Commit::factory(), 'parent')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('user.show', ['user' => $user, 'tab' => $tab]));

    $response->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('user.commits_count', 2);

            DB::enableQueryLog();

            $page->reloadOnly('commits', fn ($reload) => $reload
                ->missing('user')
                ->missing('isFollowing')
                ->missing('followsYou')
                ->missing('tab')
                ->missing('activity')
                ->missing('achievements'));
        });

    expect(collect(DB::getQueryLog())
        ->pluck('query')
        ->reject(fn (string $query): bool => str_starts_with($query, 'select "commits"."id", "commits"."user_id"') || str_starts_with($query, 'select * from (select "commits"."id"'))
        ->all())
        ->not->toBeEmpty()
        ->each(fn ($query) => $query->not->toContain('count(*)')->not->toContain('exists'));
})->with(['commits', 'replies']);

it('does not count the profile of guests reloading only commits', function (): void {
    User::factory()->has(Commit::factory())->create(['username' => 'octocat']);

    DB::enableQueryLog();

    $this->get('/@octocat', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'user/show',
        'X-Inertia-Partial-Data' => 'commits',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'props.commits.data')
        ->assertJsonMissingPath('props.user');

    expect(collect(DB::getQueryLog())
        ->pluck('query')
        ->reject(fn (string $query): bool => str_starts_with($query, 'select "commits"."id", "commits"."user_id"') || str_starts_with($query, 'select * from (select "commits"."id"'))
        ->all())
        ->not->toBeEmpty()
        ->each(fn ($query) => $query->not->toContain('count(*)'));
});

it('counts the profile of guests once for both the page and its link preview', function (): void {
    $user = User::factory()->has(Commit::factory())->create(['username' => 'octocat']);
    $user->followers()->attach(User::factory()->create());

    DB::enableQueryLog();

    $this->get('/@octocat')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('user.commits_count', 1)
            ->where('user.followers_count', 1))
        ->assertSee('"userInteractionCount":1', false);

    expect(collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with($query, 'select count(*) as "aggregate" from "follows" where "follows"."followed_id"')))
        ->toHaveCount(1);
});

it('renders the user commit activity for the last year', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $user = User::factory()->create(['username' => 'octocat']);

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => now()->subDays(3)]);
    Commit::factory()->for($user)->create(['created_at' => now()->subYears(2)]);
    Commit::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activity.scale', 'year')
            ->has('activity.buckets', 369)
            ->where('activity.buckets.0', ['date' => '2025-10-12', 'count' => 0])
            ->where('activity.buckets.365', ['date' => '2026-10-12', 'count' => 1])
            ->where('activity.buckets.368', ['date' => '2026-10-15', 'count' => 2]));
});

it('renders the hourly commit activity of a user who only committed today', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $user = User::factory()->create(['username' => 'octocat', 'created_at' => now()->subMonths(3)]);

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => now()->subHours(3)]);

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activity.scale', 'day')
            ->has('activity.buckets', 24)
            ->where('activity.buckets.20', ['date' => '2026-10-15T09:00:00Z', 'count' => 1])
            ->where('activity.buckets.23', ['date' => '2026-10-15T12:00:00Z', 'count' => 2]));
});

it('renders the user commit streaks alongside starred commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $viewer = User::factory()->create();

    $commit = Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create(['created_at' => now()->subDays(3)]);
    Commit::factory()->for($user)->create(['created_at' => now()->subDays(4)]);
    Commit::factory()->for($user)->pending()->create(['created_at' => now()->subDay()]);

    $viewer->starredCommits()->attach($commit);

    $response = $this->actingAs($viewer)
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('streaks', ['longest' => 2, 'current' => 1])
            ->where('commits.data.0.id', $commit->id)
            ->where('commits.data.0.stars_count', 1)
            ->where('commits.data.0.starred', true));
});

it('does not compute the streaks when reloading only commits', function (string $tab): void {
    $user = User::factory()->has(Commit::factory())->create(['username' => 'octocat']);
    Commit::factory()->for($user)->for(Commit::factory(), 'parent')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('user.show', ['user' => $user, 'tab' => $tab]));

    $response->assertOk()
        ->assertInertia(function ($page) use ($tab): void {
            $page->where('tab', $tab)
                ->where('streaks', ['longest' => 1, 'current' => 1]);

            DB::enableQueryLog();

            $page->reloadOnly('commits', fn ($reload) => $reload->missing('streaks'));
        });

    expect(collect(DB::getQueryLog())->pluck('query')->all())
        ->not->toBeEmpty()
        ->each->not->toContain('distinct');
})->with(['commits', 'replies']);

it('renders whether the viewer is not following the user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('user.show', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isFollowing', false)
            ->where('isMuting', false)
            ->has('commits.data', 0));
});

it('still shows the commits of a muted user on their profile', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->has(Commit::factory())->create();

    $viewer->muting()->attach($user);

    $response = $this->actingAs($viewer)
        ->get(route('user.show', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/show')
            ->where('isMuting', true)
            ->has('commits.data', 1));
});

it('shows the blocker who they blocked, without any of their commits', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->has(Commit::factory())->create(['username' => 'octocat', 'pinned_commit_id' => null]);

    $viewer->blocking()->attach($user);

    $response = $this->actingAs($viewer)
        ->get(route('user.show', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/unavailable')
            ->where('username', 'octocat')
            ->where('blockedUser.id', $user->id)
            ->where('blockedUser.lane', $user->lane())
            ->missing('blockedUser.email')
            ->missing('commits')
            ->missing('activity'));
});

it('shows a neutral unavailable profile to someone the user blocked', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->has(Commit::factory())->create(['username' => 'octocat', 'bio' => 'Private bio']);

    $user->blocking()->attach($viewer);

    $response = $this->actingAs($viewer)
        ->get(route('user.show', ['user' => $user, 'tab' => 'replies']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/unavailable')
            ->where('username', 'octocat')
            ->where('blockedUser', null)
            ->missing('commits')
            ->missing('user'));

    expect($response->getContent())->not->toContain('Private bio');
});

it('renders whether the user follows the viewer', function (): void {
    $user = User::factory()->create();
    $viewer = User::factory()->create();

    $user->following()->attach($viewer);

    $response = $this->actingAs($viewer)
        ->get(route('user.show', $user));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isFollowing', false)
            ->where('followsYou', true));
});

it('returns not found for unknown usernames', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/@missing');

    $response->assertNotFound();
});

it('shows guests the profile with its approved commits and a link preview', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'bio' => 'I am the Octocat.',
    ]);

    Commit::factory()->for($user)->create(['body' => 'A commit everyone may read']);
    Commit::factory()->for($user)->pending()->create(['body' => 'A pending commit only its author may read']);

    $response = $this->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/show')
            ->where('user.commits_count', 1)
            ->where('isFollowing', false)
            ->where('isMuting', false)
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'A commit everyone may read')
            ->where('commits.data.0.is_saved', false)
            ->where('streaks.longest', 1)
            ->has('activity'))
        ->assertSee('<title>The Octocat (@octocat) on /dev/null</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('user.show', $user).'">', false)
        ->assertSee('<meta property="profile:username" content="octocat">', false)
        ->assertSee('"@type":"ProfilePage"', false)
        ->assertSee('"sameAs":["https://github.com/octocat"', false)
        ->assertSee('<meta property="og:type" content="profile">', false)
        ->assertSee('<meta property="og:title" content="The Octocat (@octocat) on /dev/null">', false)
        ->assertSee('<meta property="og:description" content="I am the Octocat.">', false)
        ->assertSee('<meta property="og:url" content="'.route('user.show', $user).'">', false)
        ->assertSee('<meta property="og:image" content="'.route('user-open-graph-image.show', $user).'?v=', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<link rel="alternate" type="application/atom+xml" title="The Octocat (@octocat) on /dev/null" href="'.route('user-feed.show', $user).'">', false)
        ->assertDontSee(url('/og.png'))
        ->assertDontSee('A pending commit only its author may read');

    $this->assertGuest();
});

it('describes guests a profile without a bio', function (): void {
    User::factory()->create(['username' => 'octocat', 'name' => 'octocat', 'bio' => null]);

    $this->get('/@octocat')
        ->assertOk()
        ->assertSee('<meta property="og:title" content="@octocat on /dev/null">', false)
        ->assertSee('<meta property="og:description" content="Read @octocat&#039;s commits on /dev/null, a social network for developers.">', false);
});

it('escapes the profile in the link preview', function (): void {
    User::factory()->create([
        'username' => 'octocat',
        'name' => '"><script>alert(1)</script>',
        'bio' => '<img src=x onerror=alert(1)>',
    ]);

    $this->get('/@octocat')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; (@octocat) on /dev/null', false);
});

it('changes the link preview image version only when public profile data changes', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $version = fn (): string => Str::betweenFirst($this->get('/@octocat')->getContent(), 'og.png?v=', '"');

    $initial = $version();

    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    expect($version())->toBe($initial);

    Commit::factory()->for($user)->create();

    expect($version())->not->toBe($initial);
});

it('returns guests to the profile after signing in', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $this->get('/@octocat')->assertOk();

    expect(session('url.intended'))->toBe(route('user.show', $user));
});

it('does not return guests to a profile they only prefetched or partially reloaded', function (): void {
    User::factory()->create(['username' => 'octocat']);

    $this->get('/@octocat', ['Purpose' => 'prefetch'])->assertOk();
    $this->get('/@octocat', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'user/show',
        'X-Inertia-Partial-Data' => 'commits',
    ])->assertOk();

    expect(session('url.intended'))->toBeNull();
});

it('does not change where signed-in users return to', function (): void {
    User::factory()->create(['username' => 'octocat']);

    $this->actingAs(User::factory()->create())->get('/@octocat')->assertOk();

    expect(session('url.intended'))->toBeNull();
});

it('returns not found for unknown usernames to guests', function (): void {
    $this->get('/@missing')->assertNotFound();
});

it('keeps the default link preview for signed-in users', function (): void {
    User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat']);

    $this->actingAs(User::factory()->create())
        ->get('/@octocat')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('user/show'))
        ->assertSee('<meta property="og:image" content="'.url('/og.png').'">', false)
        ->assertDontSee('og:url', false)
        ->assertDontSee('application/atom+xml', false)
        ->assertDontSee('application/ld+json', false);
});

it('may delete user account', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->delete(route('user.destroy'));

    $response->assertRedirectToRoute('home');

    expect($user->fresh())->toBeNull()
        ->and(Commit::query()->count())->toBe(0);

    $this->assertGuest();
});

it('keeps the replies other people left under the replies of a deleted account', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($root, 'parent')->create();
    $otherReply = Commit::factory()->for($reply, 'parent')->create();

    $this->actingAs($user)->delete(route('user.destroy'));

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$otherReply->user, $otherReply]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ancestors', 2)
            ->where('ancestors.0.id', $root->id)
            ->where('ancestors.1.id', $reply->id)
            ->missing('ancestors.1.body')
            ->missing('ancestors.1.user'));

    $this->actingAs($root->user)
        ->getJson(route('commit-reply.index', $root))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id)
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonMissingPath('data.0.user');

    $this->getJson(route('api-commit.show', $reply))->assertNotFound();

    $this->getJson(route('api-commit.show', $otherReply))
        ->assertOk()
        ->assertJsonPath('data.id', $otherReply->id)
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.author.id', $otherReply->user_id);

    $this->getJson(route('api-user-commit.index', $otherReply->user).'?replies=1')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$otherReply->id]);

    $this->get(route('user-feed.show', $root->user))
        ->assertOk()
        ->assertSee("urn:uuid:{$root->id}")
        ->assertDontSee("urn:uuid:{$reply->id}");

    $this->getJson(route('api-user.show', $user))->assertNotFound();
    $this->get(route('user-feed.show', $user))->assertNotFound();
});

it('hides pending and rejected commits from other users', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    Commit::factory()->for($user)->create(['body' => 'Approved']);
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('user.commits_count', 1)
            ->where('activity.buckets.23.count', 1)
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Approved'));
});

it('shows own pending and rejected commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    $response = $this->actingAs($user)
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('user.commits_count', 3)
            ->where('activity.buckets.23.count', 3)
            ->has('commits.data', 3));
});

it('hides deleted commits from profiles', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    Commit::factory()->for($user)->create(['body' => 'Kept']);
    Commit::factory()->for($user)->create()->delete();

    $response = $this->actingAs($user)
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('user.commits_count', 1)
            ->where('activity.scale', 'day')
            ->where('activity.buckets.23.count', 1)
            ->where('streaks', ['longest' => 1, 'current' => 1])
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Kept'));
});

it('only shows top-level commits on profiles', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $parent = Commit::factory()->for($user)->create(['body' => 'Root']);
    Commit::factory()->for($user)->for($parent, 'parent')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Root')
            ->where('commits.data.0.replies_count', 1));
});

it('includes the quoted commits of the user commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $quoted = Commit::factory()->for(User::factory()->state(['username' => 'taylorotwell']))->create(['body' => 'The quoted commit']);
    Commit::factory()->for($user)->for($quoted, 'quotedCommit')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.quoted_commit.body', 'The quoted commit')
            ->where('commits.data.0.quoted_commit.user.username', 'taylorotwell'));
});

it('includes the link previews of the user commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $linkPreview = LinkPreview::factory()->fetched()->create(['title' => 'Octocat links']);

    Commit::factory()->for($user)->for($linkPreview)->create();

    $response = $this->actingAs(User::factory()->create())
        ->get('/@octocat');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.link_preview.title', 'Octocat links')
            ->missing('commits.data.0.link_preview.url_hash')
            ->missing('commits.data.0.link_preview.fetched_at'));
});

it('preloads only replies visible to the profile visitor', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $blocked = User::factory()->create();
    $viewer->muting()->attach($muted);
    $blocked->blocking()->attach($viewer);
    $parent = Commit::factory()->for($author)->create();
    Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinutes(2)]);
    $ownPending = Commit::factory()->for($parent, 'parent')->for($viewer)->pending()->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($parent, 'parent')->pending()->create();
    Commit::factory()->for($parent, 'parent')->rejected()->create();
    Commit::factory()->for($parent, 'parent')->create(['deleted_at' => now()]);
    Commit::factory()->for($parent, 'quotedCommit')->for($muted)->create();
    Commit::factory()->for($parent, 'quotedCommit')->for($blocked)->create();

    $this->actingAs($viewer)->get(route('user.show', $author))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commits.data.0.replies_count', 2)
        ->where('commits.data.0.reply_preview.data.0.id', $ownPending->id)
        ->where('commits.data.0.reply_preview.next_cursor', fn (string $cursor): bool => $cursor !== '')
        ->missing('commits.data.0.reply_preview.data.0.user.email'));
});
