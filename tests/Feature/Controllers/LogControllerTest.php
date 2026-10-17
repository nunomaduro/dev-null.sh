<?php

declare(strict_types=1);

use App\Actions\UpdateCommitHotScores;
use App\Enums\CommitStatus;
use App\Enums\Log;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use App\Queries\LatestLogCommitsQuery;
use Illuminate\Support\Facades\DB;

it('renders own commits and commits of followed users newest first', function (): void {
    $user = User::factory()->create();
    $followed = User::factory()->create();
    $stranger = User::factory()->create();

    $user->following()->attach($followed);

    Commit::factory()->for($user)->create(['body' => 'Mine', 'created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($followed)->create(['body' => 'Followed', 'created_at' => now()->subMinute()]);
    Commit::factory()->for($stranger)->create(['body' => 'Stranger']);

    $response = $this->actingAs($user)
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log/show')
            ->where('log', 'following')
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Followed')
            ->where('commits.data.0.user.id', $followed->id)
            ->where('commits.data.0.stars_count', 0)
            ->where('commits.data.0.starred', false)
            ->missing('commits.data.0.user.email')
            ->where('commits.data.1.body', 'Mine'));
});

it('renders each author with their effective lane', function (): void {
    $user = User::factory()->create();
    $chosen = User::factory()->create(['preferred_lane' => 5]);
    $automatic = User::factory()->create(['id' => 'edfd235d-6874-4a82-a1ba-4f40c5f40510']);

    Commit::factory()->for($chosen)->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($automatic)->create();

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.user.lane', 2)
            ->where('commits.data.1.user.lane', 5)
            ->missing('commits.data.1.user.preferred_lane'));
});

it('renders commits of everyone newest first', function (): void {
    $user = User::factory()->create();

    $starred = Commit::factory()->create(['body' => 'Older', 'created_at' => now()->subMinute()]);
    $starred->stars()->attach(User::factory()->create());
    resolve(UpdateCommitHotScores::class)->handle([$starred->id]);
    Commit::factory()->create(['body' => 'Newer']);

    $response = $this->actingAs($user)
        ->get('/log/latest');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log/show')
            ->where('log', 'latest')
            ->where('isRanked', false)
            ->where('newestCommitId', null)
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Newer')
            ->where('commits.data.1.body', 'Older'));
});

it('renders commits of everyone ranked by popularity', function (): void {
    $user = User::factory()->create();

    $starred = Commit::factory()->create(['body' => 'Starred', 'created_at' => now()->subHour()]);
    $starred->stars()->attach(User::factory()->create());
    resolve(UpdateCommitHotScores::class)->handle([$starred->id]);
    Commit::factory()->create(['body' => 'Newer']);

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log/show')
            ->where('log', 'trending')
            ->where('isRanked', true)
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Starred')
            ->where('commits.data.1.body', 'Newer'));
});

it('redirects the former everyone log to the trending log', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/log/everyone');

    $response->assertRedirect('/log/trending')
        ->assertStatus(301);
});

it('paginates the log', function (Log $log): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(25)->sequence(
        fn ($sequence): array => ['created_at' => now()->subMinutes($sequence->index)],
    )->create();

    $response = $this->actingAs($user)
        ->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 20)
            ->whereNot('commits.next_cursor', null));
})->with(Log::cases());

it('does not count the authenticated user relations when reloading only commits', function (): void {
    $user = User::factory()->has(Commit::factory())->create();

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('auth.user.commits_count', 1);

            DB::enableQueryLog();

            $page->reloadOnly('commits', fn ($reload) => $reload->missing('auth'));
        });

    expect(collect(DB::getQueryLog())
        ->pluck('query')
        ->reject(fn (string $query): bool => str_starts_with($query, 'select "commits"."id", "commits"."user_id"') || str_starts_with($query, 'select * from (select "commits"."id"'))
        ->all())
        ->not->toBeEmpty()
        ->each->not->toContain('count(*)');
});

it('remembers the last visited log', function (Log $log): void {
    $user = User::factory()->create(['log' => $log === Log::Following ? Log::Trending : Log::Following]);

    $response = $this->actingAs($user)
        ->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.log', $log->value));

    expect($user->refresh()->log)->toBe($log);
})->with(Log::cases());

it('does not update the user when the log is unchanged', function (): void {
    $user = User::factory()->create(['log' => Log::Trending]);
    $updatedAt = $user->updated_at;

    $this->travel(1)->minute();

    $this->actingAs($user)
        ->get(route('log.show', Log::Trending))
        ->assertOk();

    expect($user->refresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('returns not found for unknown logs', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/log/unknown');

    $response->assertNotFound();
});

it('lets guests read the public logs', function (Log $log): void {
    $author = User::factory()->create();
    $commit = Commit::factory()->for($author)->create();

    $response = $this->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log/show')
            ->where('log', $log->value)
            ->has('commits.data', 1)
            ->where('commits.data.0.id', $commit->id));
})->with([Log::Latest, Log::Trending]);

it('sends guests to sign in with GitHub for the following log', function (): void {
    $this->get(route('log.show', Log::Following))
        ->assertRedirect(route('login'));

    $this->get(route('log.show', Log::Following), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request())])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('login'));
});

it('counts newer commits on the trending log for guests', function (): void {
    $older = Commit::factory()->create(['created_at' => now()->subMinute()]);
    Commit::factory()->create();

    $this->get(route('log.show', [Log::Trending, 'since' => $older->id]), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'log/show',
        'X-Inertia-Partial-Data' => 'newCommitsCount',
    ])->assertOk()->assertJsonPath('props.newCommitsCount', 1);
});

it('counts commits newer than a given commit', function (): void {
    $user = User::factory()->create();
    $followed = User::factory()->create();
    $user->following()->attach($followed);

    $seen = Commit::factory()->for($followed)->create(['created_at' => now()->subMinutes(5)]);
    Commit::factory()->for($followed)->count(2)->create();
    Commit::factory()->for($followed)->create(['created_at' => now()->subMinutes(10)]);
    Commit::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'newCommitsCount',
        ])
        ->get('/log/following?since='.$seen->id);

    $response->assertOk()
        ->assertJsonPath('props.newCommitsCount', 2);
});

it('counts new commits since a commit that was deleted, without deleted ones', function (): void {
    $user = User::factory()->create();

    $seen = Commit::factory()->for($user)->create(['created_at' => now()->subMinutes(5)]);
    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create()->delete();
    $seen->delete();

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'newCommitsCount',
        ])
        ->get('/log/following?since='.$seen->id);

    $response->assertOk()
        ->assertJsonPath('props.newCommitsCount', 1);
});

it('counts commits created in the same second after a given commit', function (): void {
    $user = User::factory()->create();
    $createdAt = now()->startOfSecond();

    Commit::factory()->for($user)->create(['id' => '01a14074-2549-72dc-8b4d-cb2ab04034c7', 'created_at' => $createdAt]);
    $seen = Commit::factory()->for($user)->create(['id' => '01a14074-2549-72dc-8b4d-cb2ab04034c8', 'created_at' => $createdAt]);
    Commit::factory()->for($user)->create(['id' => '01a14074-2549-72dc-8b4d-cb2ab04034c9', 'created_at' => $createdAt]);

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'newCommitsCount',
        ])
        ->get('/log/following?since='.$seen->id);

    $response->assertOk()
        ->assertJsonPath('props.newCommitsCount', 1);
});

it('does not count new commits on a regular visit', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->missing('newCommitsCount'));
});

it('suggests recent commits from other developers when following nobody', function (): void {
    $user = User::factory()->create();
    $author = User::factory()->create();

    Commit::factory()->for($author)->create(['body' => 'Older', 'created_at' => now()->subMinute()]);
    Commit::factory()->for($author)->create(['body' => 'Newer']);
    Commit::factory()->for(User::factory())->create(['created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('suggestions', 2)
            ->where('suggestions.0.body', 'Newer')
            ->where('suggestions.0.user.id', $author->id)
            ->where('suggestions.1.user.id', fn (string $id): bool => $id !== $author->id && $id !== $user->id));
});

it('does not suggest commits when following someone', function (): void {
    $user = User::factory()->create();
    $user->following()->attach(User::factory()->create());
    Commit::factory()->create();

    $response = $this->actingAs($user)
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('suggestions', []));
});

it('hides pending commits of others from the log', function (Log $log): void {
    $user = User::factory()->create();
    $followed = User::factory()->create();

    $user->following()->attach($followed);

    Commit::factory()->for($user)->pending()->create(['body' => 'Mine']);
    Commit::factory()->for($followed)->pending()->create(['body' => 'Followed']);

    $response = $this->actingAs($user)
        ->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Mine')
            ->where('commits.data.0.status', 'pending'));
})->with(Log::cases());

it('only suggests approved commits', function (): void {
    $user = User::factory()->create();

    Commit::factory()->pending()->create(['body' => 'Pending']);
    Commit::factory()->rejected()->create(['body' => 'Rejected']);
    Commit::factory()->create(['body' => 'Approved']);

    $response = $this->actingAs($user)
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('suggestions', 1)
            ->where('suggestions.0.body', 'Approved'));
});

it('only shows top-level commits with their reply counts', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create(['body' => 'Root']);
    Commit::factory()->for($parent, 'parent')->count(2)->create();
    Commit::factory()->for($parent, 'parent')->pending()->create();

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Root')
            ->where('commits.data.0.replies_count', 2));
});

it('only suggests top-level commits', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create(['body' => 'Root commit', 'created_at' => now()->subMinute()]);
    Commit::factory()->for($parent, 'parent')->create(['body' => 'A reply']);

    $response = $this->actingAs($user)
        ->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('suggestions', 1)
            ->where('suggestions.0.body', 'Root commit'));
});

it('includes the link previews of commits', function (Log $log): void {
    $user = User::factory()->create();

    $linkPreview = LinkPreview::factory()->fetched()->create([
        'url' => 'https://laravel.com',
        'title' => 'Laravel',
        'description' => 'The PHP framework for web artisans.',
        'image_url' => 'https://laravel.com/og.png',
        'site_name' => 'Laravel',
    ]);

    Commit::factory()->for($user)->for($linkPreview)->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.link_preview', null)
            ->where('commits.data.1.link_preview', [
                'url' => 'https://laravel.com',
                'title' => 'Laravel',
                'description' => 'The PHP framework for web artisans.',
                'image_url' => 'https://laravel.com/og.png',
                'site_name' => 'Laravel',
                'is_pending' => false,
                'embed' => null,
            ]));
})->with(Log::cases());

it('includes the quoted commits with their authors', function (Log $log): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->for(User::factory()->state(['username' => 'octocat']))->create(['body' => 'The quoted commit', 'created_at' => now()->subHour()]);
    $hidden = Commit::factory()->create(['body' => 'A rejected quoted commit']);
    $deleted = Commit::factory()->create(['body' => 'A deleted quoted commit']);
    Commit::factory()->for($user)->for($quoted, 'quotedCommit')->create(['created_at' => now()->subMinutes(3)]);
    Commit::factory()->for($user)->for($hidden, 'quotedCommit')->create(['created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($user)->for($deleted, 'quotedCommit')->create(['created_at' => now()->subMinute()]);
    $hidden->update(['status' => CommitStatus::Rejected]);
    $deleted->delete();

    $response = $this->actingAs($user)
        ->get(route('log.show', $log));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.quoted_commit.id', $deleted->id)
            ->missing('commits.data.0.quoted_commit.body')
            ->where('commits.data.1.quoted_commit_id', $hidden->id)
            ->where('commits.data.1.quoted_commit', null)
            ->where('commits.data.2.quoted_commit.body', 'The quoted commit')
            ->where('commits.data.2.quoted_commit.user.username', 'octocat'))
        ->assertDontSee('A rejected quoted commit')
        ->assertDontSee('A deleted quoted commit');
})->with(Log::cases());

it('loads the quoted commits of a log without a query per commit', function (Log $log): void {
    $user = User::factory()->create(['log' => $log]);

    $queries = function () use ($user, $log): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('log.show', $log))->assertOk();

        return count(DB::getQueryLog());
    };

    Commit::factory()->for($user)->for(Commit::factory(), 'quotedCommit')->create();

    $expected = $queries();

    Commit::factory()->for($user)->for(Commit::factory(), 'quotedCommit')->count(5)->create();

    expect($queries())->toBe($expected);
})->with(Log::cases());

it('defers suggested users to follow', function (): void {
    $user = User::factory()->create();
    $suggested = User::factory()->has(Commit::factory())->create();

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('showsWhoToFollow', true)
            ->missing('suggestedUsers')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('suggestedUsers', 1)
                ->where('suggestedUsers.0.id', $suggested->id)
                ->where('suggestedUsers.0.followed_by_following_count', 0)
                ->missing('suggestedUsers.0.email')));

    expect(collect($response->viewData('page')['deferredProps'] ?? [])->flatten())->toContain('suggestedUsers');
});

it('keeps the suggested users the client already loaded when navigating', function (): void {
    $user = User::factory()->create();
    User::factory()->has(Commit::factory())->create();

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Except-Once-Props' => 'suggestedUsers',
        ])
        ->get('/log/trending');

    $response->assertOk()
        ->assertJsonMissingPath('props.suggestedUsers')
        ->assertJsonPath('onceProps.suggestedUsers.prop', 'suggestedUsers');

    expect(collect($response->json('deferredProps') ?? [])->flatten())->not->toContain('suggestedUsers');
});

it('does not suggest users to follow once they are hidden', function (): void {
    $user = User::factory()->create();
    User::factory()->has(Commit::factory())->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('who_to_follow', 'false')
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('showsWhoToFollow', false)
            ->missing('suggestedUsers'));

    expect(collect($response->viewData('page')['deferredProps'] ?? [])->flatten())->not->toContain('suggestedUsers');
});

it('offers the commits newer than a given one on top of the ranked log, newest first', function (): void {
    $user = User::factory()->create();

    $seen = Commit::factory()->create(['created_at' => now()->subMinutes(5)]);
    $older = Commit::factory()->create(['created_at' => now()->subMinute()]);
    $newer = Commit::factory()->create();

    $response = $this->actingAs($user)
        ->get('/log/trending');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('newestCommitId', $newer->id)
            ->where('latestCommits', [])
            ->reloadOnly(['latestCommits'], fn ($reload) => $reload->where('latestCommits', [])));

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'latestCommits',
        ])
        ->get('/log/trending?since='.$seen->id);

    $response->assertOk()
        ->assertJsonPath('props.latestCommits.0.id', $newer->id)
        ->assertJsonPath('props.latestCommits.1.id', $older->id)
        ->assertJsonCount(2, 'props.latestCommits')
        ->assertJsonPath('prependProps', ['latestCommits']);
});

it('offers the commit just committed on top of the ranked log', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $user = User::factory()->create(['username' => 'nunomaduro']);
    $starred = Commit::factory()->create(['created_at' => now()->subHour()]);
    $starred->stars()->attach(User::factory()->create());

    $response = $this->actingAs($user)
        ->from('/log/trending')
        ->followingRedirects()
        ->post(route('commit.store'), ['body' => 'Initial commit']);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('latestCommits', 1)
            ->where('latestCommits.0.body', 'Initial commit'));
});

it('leaves the user own commits out of the newer commits of the ranked log', function (): void {
    $user = User::factory()->create();

    $seen = Commit::factory()->create(['created_at' => now()->subMinutes(5)]);
    Commit::factory()->for($user)->create();
    $other = Commit::factory()->create();

    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'log/show',
    ];

    $this->actingAs($user)
        ->withHeaders([...$headers, 'X-Inertia-Partial-Data' => 'newCommitsCount'])
        ->get('/log/trending?since='.$seen->id)
        ->assertJsonPath('props.newCommitsCount', 1);

    $this->actingAs($user)
        ->withHeaders([...$headers, 'X-Inertia-Partial-Data' => 'latestCommits'])
        ->get('/log/trending?since='.$seen->id)
        ->assertJsonCount(1, 'props.latestCommits')
        ->assertJsonPath('props.latestCommits.0.id', $other->id);
});

it('offers the oldest of too many newer commits, so the rest are offered next', function (): void {
    $user = User::factory()->create();

    $seen = Commit::factory()->create(['created_at' => now()->subHour()]);
    $commits = Commit::factory()->count(LatestLogCommitsQuery::LIMIT + 1)->sequence(
        fn ($sequence): array => ['created_at' => now()->subMinutes(50)->addSeconds($sequence->index)],
    )->create();

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'latestCommits',
        ])
        ->get('/log/trending?since='.$seen->id);

    $response->assertJsonCount(LatestLogCommitsQuery::LIMIT, 'props.latestCommits')
        ->assertJsonPath('props.latestCommits.0.id', $commits[LatestLogCommitsQuery::LIMIT - 1]->id)
        ->assertJsonPath('props.latestCommits.'.(LatestLogCommitsQuery::LIMIT - 1).'.id', $commits[0]->id);
});

it('preloads the latest reply per feed commit with a cursor to the older replies', function (): void {
    $viewer = User::factory()->create();
    $parents = Commit::factory()->for($viewer)->count(2)->create();
    foreach ($parents as $parent) {
        Commit::factory()->for($parent, 'parent')->create(['body' => 'Older reply', 'created_at' => now()->subMinutes(2)]);
        Commit::factory()->for($parent, 'quotedCommit')->create(['body' => 'Latest quote', 'created_at' => now()->subMinute()]);
        Commit::factory()->for($parent, 'parent')->pending()->create();
    }

    $response = $this->actingAs($viewer)->get(route('log.show', 'latest'));
    $response->assertOk()->assertInertia(function ($page): void {
        foreach ([0, 1] as $index) {
            $page->has("commits.data.{$index}.reply_preview.data", 1)
                ->where("commits.data.{$index}.reply_preview.data.0.body", 'Latest quote');
        }
    });
    foreach (array_slice($response->inertiaProps('commits.data'), 0, 2) as $commit) {
        $this->getJson(route('commit-reply.index', [
            $commit['id'], 'newest' => 1, 'cursor' => $commit['reply_preview']['next_cursor'],
        ]))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Older reply')
            ->assertJsonPath('next_cursor', null);
    }
});
