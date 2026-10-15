<?php

declare(strict_types=1);

use App\Actions\ResolveOmarchyTheme;
use App\Enums\Log;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\User;
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
            ->missing('commits.data.0.user.email')
            ->where('commits.data.1.body', 'Mine'));
});

it('renders commits of everyone newest first', function (): void {
    $user = User::factory()->create();

    Commit::factory()->create(['body' => 'Older', 'created_at' => now()->subMinute()]);
    Commit::factory()->create(['body' => 'Newer']);

    $response = $this->actingAs($user)
        ->get('/log/everyone');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log/show')
            ->where('log', 'everyone')
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Newer')
            ->where('commits.data.1.body', 'Older'));
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
        ->get('/log/everyone');

    $response->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('auth.user.commits_count', 1);

            DB::enableQueryLog();

            $page->reloadOnly('commits', fn ($reload) => $reload->missing('auth'));
        });

    expect(collect(DB::getQueryLog())
        ->pluck('query')
        ->reject(fn (string $query): bool => str_starts_with($query, 'select "commits".*'))
        ->all())
        ->not->toBeEmpty()
        ->each->not->toContain('count(*)');
});

it('returns not found for unknown logs', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/log/unknown');

    $response->assertNotFound();
});

it('requires authentication', function (Log $log): void {
    $response = $this->get(route('log.show', $log));

    $response->assertRedirectToRoute('home');
})->with(Log::cases());

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
            'X-Inertia-Version' => (string) new HandleInertiaRequests(resolve(ResolveOmarchyTheme::class))->version(request()),
            'X-Inertia-Partial-Component' => 'log/show',
            'X-Inertia-Partial-Data' => 'newCommitsCount',
        ])
        ->get('/log/following?since='.$seen->id);

    $response->assertOk()
        ->assertJsonPath('props.newCommitsCount', 2);
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
        ->get('/log/everyone');

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
