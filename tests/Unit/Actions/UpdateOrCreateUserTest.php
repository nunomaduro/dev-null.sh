<?php

declare(strict_types=1);

use App\Actions\UpdateOrCreateUser;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\User as GitHubUser;

it('creates a user from github', function (): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'id' => 583231,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar' => 'https://avatars.githubusercontent.com/u/583231',
        'bio' => ' I am the Octocat. ',
        'location' => 'San Francisco',
        'blog' => 'https://github.blog',
        'company' => '@github',
    ]));

    expect($user->exists)->toBeTrue()
        ->and($user->github_id)->toBe('583231')
        ->and($user->username)->toBe('octocat')
        ->and($user->name)->toBe('The Octocat')
        ->and($user->email)->toBe('octocat@github.com')
        ->and($user->avatar_url)->toBe('https://avatars.githubusercontent.com/u/583231')
        ->and($user->bio)->toBe('I am the Octocat.')
        ->and($user->location)->toBe('San Francisco')
        ->and($user->website)->toBe('https://github.blog')
        ->and($user->company)->toBe('@github');
});

it('updates an existing user from github', function (): void {
    fakeGitHubSponsorsListing();

    $existing = User::factory()->create([
        'github_id' => '583231',
        'username' => 'old-username',
        'bio' => 'Old bio',
    ]);

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'id' => '583231',
        'nickname' => 'octocat',
        'bio' => 'New bio',
    ]));

    expect($user->is($existing))->toBeTrue()
        ->and(User::query()->count())->toBeOne()
        ->and($existing->refresh()->username)->toBe('octocat')
        ->and($existing->bio)->toBe('New bio');
});

it('falls back to the username when the name is blank', function (): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'nickname' => 'octocat',
        'name' => null,
    ]));

    expect($user->name)->toBe('octocat');
});

it('stores blank profile fields as null', function (): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'email' => null,
        'avatar' => '',
        'bio' => '   ',
        'location' => null,
        'blog' => '',
    ]));

    expect($user->email)->toBeNull()
        ->and($user->avatar_url)->toBeNull()
        ->and($user->bio)->toBeNull()
        ->and($user->location)->toBeNull()
        ->and($user->website)->toBeNull()
        ->and($user->company)->toBeNull();
});

it('adds a scheme to websites without one', function (): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'blog' => 'nunomaduro.com',
    ]));

    expect($user->website)->toBe('https://nunomaduro.com');
});

it('keeps http and https websites as they are regardless of scheme case', function (string $website): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'blog' => $website,
    ]));

    expect($user->refresh()->website)->toBe($website);
})->with([
    'http' => 'http://example.com/Path?Key=Value#Section',
    'https' => 'https://example.com/Path?Key=Value#Section',
    'uppercase http' => 'HTTP://example.com/Path?Key=Value#Section',
    'uppercase https' => 'HTTPS://example.com/Path?Key=Value#Section',
    'mixed-case http' => 'HtTp://example.com/Path?Key=Value#Section',
    'mixed-case https' => 'HtTpS://example.com/Path?Key=Value#Section',
]);

it('marks users with a sponsors listing', function (): void {
    Log::spy();

    fakeGitHubSponsorsListing(hasSponsorsListing: true);

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'token' => 'gho_octocat',
    ]));

    expect($user->has_sponsors_listing)->toBeTrue()
        ->and($user->github_synced_at->toDateTimeString())->toBe(now()->toDateTimeString());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/graphql'
        && $request->hasHeader('Authorization', 'Bearer gho_octocat')
        && $request['query'] === 'query { viewer { hasSponsorsListing } }');

    Log::shouldNotHaveReceived('warning');
});

it('unmarks users without a sponsors listing', function (): void {
    $existing = User::factory()->create([
        'github_id' => '583231',
        'has_sponsors_listing' => true,
    ]);

    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $action->handle(GitHubUser::fake([
        'id' => '583231',
    ]));

    expect($existing->refresh()->has_sponsors_listing)->toBeFalse();
});

it('keeps the last known sponsors listing when github cannot say', function (Closure $response): void {
    Log::spy();

    $stale = User::factory()->create([
        'github_id' => '1',
        'username' => 'octocat',
    ]);

    $existing = User::factory()->create([
        'github_id' => '583231',
        'has_sponsors_listing' => true,
    ]);

    Http::fake([
        'api.github.com/graphql' => $response,
    ]);

    $action = resolve(UpdateOrCreateUser::class);

    $action->handle(GitHubUser::fake([
        'id' => '583231',
        'nickname' => 'octocat',
    ]));

    expect($existing->refresh())
        ->username->toBe('octocat')
        ->has_sponsors_listing->toBeTrue()
        ->github_synced_at->toDateTimeString()->toBe(now()->toDateTimeString());

    expect($stale->refresh()->username)->toBe('_renamed-1');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Could not check the GitHub Sponsors listing.', Mockery::on(fn (array $context): bool => $context['username'] === 'octocat'));
})->with([
    'server error' => fn () => Http::response(status: 502),
    'graphql error' => fn () => Http::response(['errors' => [['message' => 'API rate limit exceeded']]]),
    'connection failure' => fn () => Http::failedConnection(),
]);

it('logs why github could not say', function (): void {
    Log::spy();

    $errors = [[
        'type' => 'INSUFFICIENT_SCOPES',
        'message' => "The 'isPublic' field requires one of the following scopes: ['read:user', 'read:org'].",
    ]];

    Http::fake([
        'api.github.com/graphql' => Http::response(['errors' => $errors]),
    ]);

    $action = resolve(UpdateOrCreateUser::class);

    $action->handle(GitHubUser::fake([
        'nickname' => 'octocat',
    ]));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Could not check the GitHub Sponsors listing.', [
            'username' => 'octocat',
            'status' => 200,
            'errors' => $errors,
        ]);
});

it('creates users without a sponsors listing when github cannot say', function (): void {
    Http::fake([
        'api.github.com/graphql' => Http::response(status: 502),
    ]);

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake());

    expect($user->refresh()->has_sponsors_listing)->toBeFalse();
});

it('records when the profile was synced from github', function (): void {
    fakeGitHubSponsorsListing();

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([]));

    expect($user->refresh()->github_synced_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('frees a username still held by a stale user who renamed on github', function (string $staleUsername): void {
    fakeGitHubSponsorsListing(hasSponsorsListing: true);

    $stale = User::factory()->create([
        'github_id' => '1',
        'username' => $staleUsername,
        'bio' => 'Renamed away',
    ]);

    $action = resolve(UpdateOrCreateUser::class);

    $user = $action->handle(GitHubUser::fake([
        'id' => '583231',
        'nickname' => 'octocat',
    ]));

    expect($user->username)->toBe('octocat')
        ->and($user->github_id)->toBe('583231')
        ->and($user->has_sponsors_listing)->toBeTrue()
        ->and($user->is($stale))->toBeFalse()
        ->and(User::query()->count())->toBe(2)
        ->and($stale->refresh()->username)->toBe('_renamed-1')
        ->and($stale->github_id)->toBe('1')
        ->and($stale->bio)->toBe('Renamed away');
})->with(['octocat', 'OctoCat']);
