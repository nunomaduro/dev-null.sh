<?php

declare(strict_types=1);

use App\Models\User;
use App\Queries\GitHubActivityQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeGitHubActivity(User $user, string $login = 'taylorotwell', ?string $html = null): void
{
    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response(['login' => $login]),
        "github.com/users/{$login}/contributions" => Http::response($html ?? (string) file_get_contents(base_path('tests/Fixtures/github/contributions.html'))),
    ]);
}

it('reads the contribution calendar of the account behind the user, once an hour', function (): void {
    $user = User::factory()->create(['username' => 'stale-username']);
    fakeGitHubActivity($user);

    $query = resolve(GitHubActivityQuery::class);
    $activity = $query->get($user);

    expect($activity['login'] ?? null)->toBe('taylorotwell')
        ->and($activity['days'] ?? [])->toHaveCount(4)
        ->and($query->get($user))->toBe($activity);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://github.com/users/taylorotwell/contributions');

    $this->travel(GitHubActivityQuery::CACHE_SECONDS + 1)->seconds();

    $query->get($user);

    Http::assertSentCount(4);
});

it('has no activity for an account that no longer exists', function (): void {
    $user = User::factory()->create();
    Http::fake(["api.github.com/user/{$user->github_id}" => Http::response([], 404)]);

    expect(resolve(GitHubActivityQuery::class)->get($user))->toBeNull();
});

it('does not cache a failed read, so the next request tries again', function (): void {
    $user = User::factory()->create();
    Http::fake(["api.github.com/user/{$user->github_id}" => Http::failedConnection()]);

    expect(resolve(GitHubActivityQuery::class)->get($user))->toBeNull()
        ->and(Cache::has("github-activity:{$user->github_id}"))->toBeFalse();
});

it('has no activity when github answers with an error or without a calendar', function (string $answer): void {
    $user = User::factory()->create();

    if ($answer === 'error') {
        Http::fake([
            "api.github.com/user/{$user->github_id}" => Http::response(['login' => 'taylorotwell']),
            'github.com/users/taylorotwell/contributions' => Http::response('', 500),
        ]);
    } else {
        fakeGitHubActivity($user, html: '<p>Not Found</p>');
    }

    expect(resolve(GitHubActivityQuery::class)->get($user))->toBeNull();
})->with(['error', 'no calendar']);

it('never asks github for a login that is not a github username', function (): void {
    $user = User::factory()->create();
    Http::fake(["api.github.com/user/{$user->github_id}" => Http::response(['login' => '../evil'])]);

    expect(resolve(GitHubActivityQuery::class)->get($user))->toBeNull();

    Http::assertSentCount(1);
});
