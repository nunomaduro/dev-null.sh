<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('lists the public top-level commits of a user, newest first', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $older = Commit::factory()->for($user)->create(['created_at' => now()->subDay()]);
    $newer = Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->for($older, 'parent')->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();
    Commit::factory()->for($user)->create()->delete();
    Commit::factory()->create();

    $response = $this->getJson('/api/v1/users/octocat/commits');

    $response->assertOk()
        ->assertJsonPath('data.*.id', [$newer->id, $older->id])
        ->assertJsonPath('data.0.author.username', 'octocat')
        ->assertJsonPath('data.0.html_url', route('commit.show', [$user, $newer]))
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('links.next', null);
});

it('lists the public replies of a user', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $parent = Commit::factory()->create();

    Commit::factory()->for($user)->create();
    $reply = Commit::factory()->for($user)->for($parent, 'parent')->create();
    Commit::factory()->for($user)->for($parent, 'parent')->pending()->create();
    Commit::factory()->for($user)->for($parent, 'parent')->create()->delete();

    $this->getJson('/api/v1/users/octocat/commits?replies=1')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$reply->id])
        ->assertJsonPath('data.0.parent_id', $parent->id);
});

it('pages through commits with a cursor', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $commits = Commit::factory()->for($user)->count(25)->sequence(
        fn ($sequence): array => ['created_at' => now()->subMinutes($sequence->index)],
    )->create();

    $first = $this->getJson('/api/v1/users/octocat/commits?replies=0')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20);

    $next = $first->json('links.next');

    expect($next)->toContain('replies=0')
        ->and($first->json('meta.next_cursor'))->toBeString();

    $this->getJson($next)
        ->assertOk()
        ->assertJsonPath('data.*.id', $commits->slice(20)->pluck('id')->values()->all())
        ->assertJsonPath('meta.next_cursor', null);
});

it('validates the replies filter', function (): void {
    User::factory()->create(['username' => 'octocat']);

    $this->getJson('/api/v1/users/octocat/commits?replies=maybe')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('replies');
});

it('returns not found for unknown usernames', function (): void {
    $this->getJson('/api/v1/users/missing/commits')->assertNotFound();
});

it('loads a page of commits without a query per commit', function (string $replies): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $create = fn (int $count) => Commit::factory()->for($user)->count($count)
        ->for(LinkPreview::factory()->fetched())
        ->has(CommitImage::factory()->count(2)->sequence(['position' => 0], ['position' => 1]), 'images')
        ->state(fn (): array => ['parent_id' => $replies === '1' ? Commit::factory()->create()->id : null])
        ->create();

    $queries = function () use ($replies): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/v1/users/octocat/commits?replies={$replies}")->assertOk();

        return count(DB::getQueryLog());
    };

    $create(1);
    $withOne = $queries();

    $create(5);

    expect($queries())->toBe($withOne);
})->with(['commits' => '0', 'replies' => '1']);

it('lists for a signed request the commits of a profile as the web shows them to its user', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create(['username' => 'octocat']);

    $older = Commit::factory()->for($user)->create(['created_at' => now()->subMinute()]);
    $newer = Commit::factory()->for($user)->create();
    Commit::factory()->for($newer, 'parent')->create();
    Commit::factory()->for($newer, 'parent')->pending()->create();
    Commit::factory()->for($user)->for($older, 'parent')->create();
    Commit::factory()->for($user)->pending()->create();
    $newer->stars()->attach($viewer);

    signedApiRequest($keyPair, 'GET', '/api/v1/users/octocat/commits')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$newer->id, $older->id])
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonPath('data.0.stars_count', 1)
        ->assertJsonPath('data.0.starred', true)
        ->assertJsonPath('data.0.is_saved', false)
        ->assertJsonPath('data.0.status', 'approved')
        ->assertJsonPath('data.0.author.lane', $user->lane())
        ->assertJsonMissingPath('data.0.parent')
        ->assertJsonPath('meta.next_cursor', null);
});

it('lists the pending commits of a signed-in user on their own profile', function (Closure $commit, string $replies): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $pending = $commit($user);

    $this->getJson(route('api-user-commit.index', [$user, 'replies' => $replies]))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', [$user, 'replies' => $replies]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$pending->id])
        ->assertJsonPath('data.0.status', 'pending');
})->with([
    'commits' => [fn (User $user): Commit => Commit::factory()->for($user)->pending()->create(), '0'],
    'replies' => [fn (User $user): Commit => Commit::factory()->for($user)->for(Commit::factory()->pending(), 'parent')->pending()->create(), '1'],
]);

it('leaves the pinned commit out of the commits a signed request lists', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create();
    $other = Commit::factory()->for($user)->create(['created_at' => now()->subMinute()]);
    $user->forceFill(['pinned_commit_id' => $pinned->id])->save();

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', $user))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$other->id]);
});

it('lists for a signed request the replies of a user with the commits they reply to', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create();
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($parent, 'parent')->create();
    $hidden = Commit::factory()->for($user)->for(Commit::factory()->pending(), 'parent')->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->for($parent, 'parent')->pending()->create();

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', [$user, 'replies' => 1]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$reply->id, $hidden->id])
        ->assertJsonPath('data.0.parent.id', $parent->id)
        ->assertJsonPath('data.0.parent.author.id', $parent->user_id)
        ->assertJsonPath('data.1.parent_id', $hidden->parent_id)
        ->assertJsonPath('data.1.parent', null)
        ->assertJsonMissingPath('data.2');
});

it('keeps the replies filter in the link to the next page of a signed request', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    Commit::factory()->for($user)->for($parent, 'parent')->count(21)->sequence(
        fn ($sequence): array => ['created_at' => now()->subMinutes($sequence->index)],
    )->create();

    $first = signedApiRequest($keyPair, 'GET', route('api-user-commit.index', [$user, 'replies' => 1]))
        ->assertOk()
        ->assertJsonCount(20, 'data');

    expect($first->json('links.next'))->toContain('replies=1');

    signedApiRequest($keyPair, 'GET', $first->json('links.next'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('loads a page of commits for a signed request without a query per commit', function (string $replies): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create(['username' => 'octocat']);

    $create = fn (int $count) => Commit::factory()->for($user)->count($count)
        ->for(LinkPreview::factory()->fetched())
        ->has(CommitImage::factory()->count(2)->sequence(['position' => 0], ['position' => 1]), 'images')
        ->state(fn (): array => ['parent_id' => $replies === '1' ? Commit::factory()->create()->id : null])
        ->create();

    $queries = function () use ($keyPair, $replies): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        signedApiRequest($keyPair, 'GET', "/api/v1/users/octocat/commits?replies={$replies}")->assertOk();

        return count(DB::getQueryLog());
    };

    // The first signed request also syncs the user's keys with GitHub...
    $queries();

    $create(1);
    $withOne = $queries();

    $create(5);

    expect($queries())->toBe($withOne);
})->with(['commits' => '0', 'replies' => '1']);

it('lists no commits of a profile across a block for a signed request', function (Closure $block, string $code): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $user = User::factory()->create();
    Commit::factory()->for($user)->create();

    $block($viewer, $user);

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', $user))
        ->assertForbidden()
        ->assertJsonPath('code', $code);

    $this->getJson(route('api-user-commit.index', $user))
        ->assertOk()
        ->assertJsonCount(1, 'data');
})->with([
    'blocking' => [fn (User $viewer, User $user) => $viewer->blocking()->attach($user), 'user_blocked'],
    'blocked' => [fn (User $viewer, User $user) => $user->blocking()->attach($viewer), 'user_unavailable'],
]);

it('shows a signed request the newest reply or quote under each commit, as the web does', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['created_at' => now()->subMinutes(3)]);
    Commit::factory()->for($commit, 'parent')->create(['created_at' => now()->subMinutes(2)]);
    $quote = Commit::factory()->for($commit, 'quotedCommit')->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($user)->create(['created_at' => now()->subMinutes(4)]);

    signedApiRequest($keyPair, 'GET', route('api-user-commit.index', $user))
        ->assertOk()
        ->assertJsonPath('data.0.reply_preview.data.*.id', [$quote->id])
        ->assertJsonPath('data.0.reply_preview.data.0.quoted_commit_id', $commit->id)
        ->assertJsonMissingPath('data.0.reply_preview.data.0.quoted_commit')
        ->assertJsonPath('data.1.reply_preview', ['data' => [], 'next_cursor' => null]);

    $this->getJson(route('api-user-commit.index', $user))
        ->assertOk()
        ->assertJsonMissingPath('data.0.reply_preview');
});
