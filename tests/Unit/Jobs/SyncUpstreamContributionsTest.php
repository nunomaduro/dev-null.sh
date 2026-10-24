<?php

declare(strict_types=1);

use App\Jobs\SyncUpstreamContributions;
use App\Models\User;
use App\Queries\UpstreamContributionsQuery;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @return array{login: string, id: int, type: string, contributions: int}
 */
function upstreamContributor(int $id, int $contributions = 1, string $type = 'User'): array
{
    return ['login' => "developer{$id}", 'id' => $id, 'type' => $type, 'contributions' => $contributions];
}

/**
 * Serves the contributor list a hundred to a page, like GitHub does.
 *
 * @param  list<array<string, mixed>>  $contributors
 */
function fakeUpstreamContributors(array $contributors): void
{
    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => fn (Request $request) => Http::response(
        array_slice($contributors, ((int) $request['page'] - 1) * 100, 100),
    )]);
}

it('stores the changes github counts per account, keyed by the numeric id and without the bots', function (): void {
    $user = User::factory()->create(['github_id' => '583231']);
    $other = User::factory()->create(['github_id' => '42']);

    fakeUpstreamContributors([
        upstreamContributor(583231, 7),
        upstreamContributor(42, 1),
        upstreamContributor(7, 120),
        upstreamContributor(9, 3, type: 'Bot'),
    ]);

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7, '42' => 1, '7' => 120])
        ->and(resolve(UpstreamContributionsQuery::class)->countFor($user))->toBe(7)
        ->and(resolve(UpstreamContributionsQuery::class)->countFor($other))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request['per_page'] === 100 && $request['page'] === 1);
});

it('reads contributions from the configured repository', function (): void {
    config(['services.github.repository' => 'example/dev-null']);
    fakeUpstreamContributors([upstreamContributor(583231)]);

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 1]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/repos/example/dev-null/contributors?'));
});

it('reads the contributor list until a page is not full', function (): void {
    fakeUpstreamContributors(array_map(fn (int $id): array => upstreamContributor($id), range(1, 150)));

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toHaveCount(150);

    Http::assertSentCount(2);
});

it('reads at most ten pages per run, bounding the requests to github', function (): void {
    fakeUpstreamContributors(array_map(fn (int $id): array => upstreamContributor($id), range(1, 1000)));

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toHaveCount(1000);

    Http::assertSentCount(10);
});

it('skips anonymous contributors and entries without a type', function (): void {
    fakeUpstreamContributors([
        ['email' => 'anonymous@example.com', 'name' => 'Anonymous', 'contributions' => 9, 'type' => 'Anonymous'],
        ['contributions' => 4],
        upstreamContributor(583231, 7),
    ]);

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7]);
});

it('throws when github fails, leaving the cached counts as they were', function (): void {
    Cache::forever(UpstreamContributionsQuery::CacheKey, ['583231' => 7]);
    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => Http::response([], 500)]);

    expect(fn () => dispatch_sync(new SyncUpstreamContributions()))->toThrow(RequestException::class);

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7]);
});

it('keeps the cached counts when a later page fails', function (): void {
    Cache::forever(UpstreamContributionsQuery::CacheKey, ['583231' => 7]);

    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => fn (Request $request) => (int) $request['page'] === 1
        ? Http::response(array_map(fn (int $id): array => upstreamContributor($id), range(1, 100)))
        : Http::response([], 500)]);

    expect(fn () => dispatch_sync(new SyncUpstreamContributions()))->toThrow(RequestException::class);

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7]);
});

it('keeps the cached counts when github does not return a contributor list', function (mixed $body): void {
    Cache::forever(UpstreamContributionsQuery::CacheKey, ['583231' => 7]);
    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => Http::response($body)]);

    expect(fn () => dispatch_sync(new SyncUpstreamContributions()))->toThrow(RuntimeException::class);

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7]);
})->with([
    'an object body' => [['message' => 'Bad credentials']],
    'an empty first page' => [[]],
]);

it('expires the stored counts a day after reading them', function (): void {
    fakeUpstreamContributors([upstreamContributor(583231, 7)]);
    $user = User::factory()->create(['github_id' => '583231']);

    dispatch_sync(new SyncUpstreamContributions());

    expect(Cache::get(UpstreamContributionsQuery::CacheKey))->toBe(['583231' => 7]);

    $this->travel(25)->hours();

    expect(resolve(UpstreamContributionsQuery::class)->countFor($user))->toBe(0);
});

it('is dispatched every hour', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->sole(fn (Event $event): bool => $event->description === SyncUpstreamContributions::class);

    expect($event->expression)->toBe('0 * * * *');
});
