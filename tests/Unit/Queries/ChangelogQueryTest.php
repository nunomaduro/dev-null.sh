<?php

declare(strict_types=1);

use App\Models\User;
use App\Queries\ChangelogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/**
 * @return array<string, mixed>
 */
function pullRequest(int $number, ?string $mergedAt, ?string $updatedAt = null, string $title = 'feat: something', string $login = 'xHeaven', int $githubId = 14284867): array
{
    return [
        'number' => $number,
        'title' => $title,
        'html_url' => "https://github.com/dev-null-sh/dev-null.sh/pull/{$number}",
        'merged_at' => $mergedAt,
        'updated_at' => $updatedAt ?? $mergedAt ?? '2026-10-01T00:00:00Z',
        'merge_commit_sha' => mb_str_pad((string) $number, 40, 'a'),
        'user' => ['id' => $githubId, 'login' => $login, 'avatar_url' => "https://avatars.githubusercontent.com/u/{$number}?v=4"],
    ];
}

/**
 * Serves the closed pull requests like GitHub does: most recently updated first, a hundred to a page.
 *
 * @param  list<array<string, mixed>>  $pullRequests
 */
function fakeGitHub(array $pullRequests): void
{
    usort($pullRequests, fn (array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

    Http::fake(['api.github.com/repos/dev-null-sh/dev-null.sh/pulls*' => fn (Request $request) => Http::response(
        array_slice($pullRequests, ((int) $request['page'] - 1) * 100, 100),
    )]);
}

/**
 * @param  array{pullRequests: list<array{number: int}>, olderBefore: string|null}|null  $changelog
 * @return list<int>
 */
function numbers(?array $changelog): array
{
    return array_column($changelog['pullRequests'] ?? [], 'number');
}

it('lists the pull requests merged on the three latest days with merges, newest merge first, with their parsed titles', function (): void {
    fakeGitHub([
        pullRequest(377, '2026-10-18T00:53:44Z', title: 'feat!: turn emoji shortcodes into emoji in remotes'),
        pullRequest(400, null, '2026-10-18T01:00:00Z', title: 'docs: closed without merging'),
        pullRequest(304, '2026-10-17T20:15:34Z', title: 'Fix GitHub website URLs with uppercase schemes', login: 'ahosker'),
        pullRequest(310, '2026-10-17T21:26:43Z', title: 'feat: dim other commits on mouse hover', login: 'simonhamp'),
        pullRequest(250, '2026-10-14T10:00:00Z'),
        pullRequest(240, '2026-10-12T10:00:00Z'),
    ]);

    $changelog = resolve(ChangelogQuery::class)->get('UTC');

    expect(numbers($changelog))->toBe([377, 310, 304, 250])
        ->and($changelog['olderBefore'] ?? null)->toBe('2026-10-14T00:00:00+00:00')
        ->and($changelog['pullRequests'][0] ?? null)->toBe([
            'number' => 377,
            'type' => 'feat',
            'breaking' => true,
            'title' => 'Turn emoji shortcodes into emoji in remotes',
            'url' => 'https://github.com/dev-null-sh/dev-null.sh/pull/377',
            'sha' => mb_str_pad('377', 40, 'a'),
            'commit_url' => 'https://github.com/dev-null-sh/dev-null.sh/commit/'.mb_str_pad('377', 40, 'a'),
            'merged_at' => '2026-10-18T00:53:44Z',
            'author' => ['login' => 'xHeaven', 'avatar' => 'https://avatars.githubusercontent.com/u/377?v=4', 'username' => null, 'mention' => null, 'lane' => null],
        ])
        ->and($changelog['pullRequests'][2] ?? [])->toHaveKey('type', null);

    Http::assertSent(fn (Request $request): bool => $request['state'] === 'closed'
        && $request['base'] === 'production'
        && $request['sort'] === 'updated'
        && $request['direction'] === 'desc'
        && $request['per_page'] === 100
        && $request['page'] === 1);
});

it('counts days in the reader’s time zone', function (): void {
    fakeGitHub([
        pullRequest(3, '2026-10-17T23:30:00Z'),
        pullRequest(2, '2026-10-17T12:00:00Z'),
        pullRequest(1, '2026-10-16T12:00:00Z'),
        pullRequest(0, '2026-10-15T12:00:00Z'),
    ]);

    $query = resolve(ChangelogQuery::class);

    expect(numbers($query->get('UTC')))->toBe([3, 2, 1, 0])
        ->and(numbers($query->get('Europe/Budapest')))->toBe([3, 2, 1])
        ->and($query->get('Europe/Budapest')['olderBefore'] ?? null)->toBe('2026-10-15T22:00:00+00:00');
});

it('reads as many pages as the three days take, and no more', function (): void {
    $busyDays = array_map(fn (int $number): array => pullRequest($number, CarbonImmutable::parse('2026-10-18T23:59:00Z')->subMinutes($number * 9)->toIso8601ZuluString()), range(1, 300));

    fakeGitHub([...$busyDays, pullRequest(2000, '2026-10-12T10:00:00Z'), ...array_map(fn (int $number): array => pullRequest(1000 + $number, '2026-10-01T10:00:00Z'), range(1, 150))]);

    $changelog = resolve(ChangelogQuery::class)->get('UTC');

    expect($changelog['pullRequests'] ?? [])->toHaveCount(301)
        ->and($changelog['olderBefore'] ?? null)->toBe('2026-10-12T00:00:00+00:00');

    Http::assertSentCount(4);
});

it('lists the next three days older than where the reader is, reusing the pages it already read', function (): void {
    fakeGitHub([
        pullRequest(6, '2026-10-18T10:00:00Z'),
        pullRequest(5, '2026-10-17T10:00:00Z'),
        pullRequest(4, '2026-10-16T10:00:00Z'),
        pullRequest(3, '2026-10-15T10:00:00Z'),
        pullRequest(2, '2026-10-13T10:00:00Z'),
        pullRequest(1, '2026-10-13T09:00:00Z'),
    ]);

    $query = resolve(ChangelogQuery::class);
    $latest = $query->get('UTC');
    $older = $query->get('UTC', CarbonImmutable::parse((string) $latest['olderBefore']));

    expect(numbers($latest))->toBe([6, 5, 4])
        ->and(numbers($older))->toBe([3, 2, 1])
        ->and($older)->toHaveKey('olderBefore', null);

    Http::assertSentCount(1);
});

it('stops reading after ten pages, carrying on from the oldest merge it got to next time', function (): void {
    fakeGitHub(array_map(fn (int $number): array => pullRequest($number, CarbonImmutable::parse('2026-10-18T23:59:00Z')->subSeconds($number * 30)->toIso8601ZuluString()), range(1, 1100)));

    $changelog = resolve(ChangelogQuery::class)->get('UTC');

    expect($changelog['pullRequests'] ?? [])->toHaveCount(1000)
        ->and($changelog['olderBefore'] ?? null)->toBe(CarbonImmutable::parse('2026-10-18T23:59:00Z')->subSeconds(1000 * 30)->toIso8601String());

    Http::assertSentCount(10);
});

it('colors each author with the lane they chose on /dev/null, found by their GitHub account', function (): void {
    User::factory()->create(['github_id' => '1', 'preferred_lane' => 4]);
    User::factory()->create(['github_id' => '2', 'lane_color' => '#ff2d20']);

    fakeGitHub([
        pullRequest(3, '2026-10-18T12:00:00Z', login: 'renamed-on-github', githubId: 1),
        pullRequest(2, '2026-10-18T11:00:00Z', login: 'custom', githubId: 2),
        pullRequest(1, '2026-10-18T10:00:00Z', login: 'not-on-devnull', githubId: 3),
    ]);

    expect(array_column(array_column(resolve(ChangelogQuery::class)->get('UTC')['pullRequests'] ?? [], 'author'), 'lane'))
        ->toBe([4, '#ff2d20', null]);
});

it('links each author who is on /dev/null to their profile, found by their GitHub account', function (): void {
    User::factory()->create(['github_id' => '1', 'username' => 'renamed-on-devnull']);

    fakeGitHub([
        pullRequest(2, '2026-10-18T12:00:00Z', login: 'renamed-on-github', githubId: 1),
        pullRequest(1, '2026-10-18T10:00:00Z', login: 'not-on-devnull', githubId: 3),
    ]);

    $authors = array_column(resolve(ChangelogQuery::class)->get('UTC')['pullRequests'] ?? [], 'author');

    expect(array_column($authors, 'username'))->toBe(['renamed-on-devnull', null])
        ->and(array_column($authors, 'mention'))->toBe(['@renamed-on-devnull', null]);
});

it('asks GitHub for a page at most once every thirty minutes', function (): void {
    fakeGitHub([pullRequest(377, '2026-10-18T00:53:44Z')]);

    $query = resolve(ChangelogQuery::class);
    $query->get('UTC');
    $query->get('Europe/Budapest');

    Http::assertSentCount(1);

    $this->travel(29)->minutes();
    $query->get('UTC');

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $query->get('UTC');

    Http::assertSentCount(2);
});

it('refreshes every cached page when the asset version changes', function (): void {
    $firstPage = array_map(fn (int $number): array => pullRequest($number, '2026-10-18T12:00:00Z'), range(101, 2));

    Http::fake(['api.github.com/*' => Http::sequence()
        ->push($firstPage)
        ->push([pullRequest(1, '2026-10-18T12:00:00Z')])
        ->push([pullRequest(102, '2026-10-18T13:00:00Z'), ...array_slice($firstPage, 0, 99)])
        ->push([pullRequest(2, '2026-10-18T12:00:00Z'), pullRequest(1, '2026-10-18T12:00:00Z')])]);

    Inertia::version('before-deploy');
    $query = resolve(ChangelogQuery::class);

    expect(numbers($query->get('UTC')))->toHaveCount(101)->not->toContain(102)
        ->and(numbers($query->get('Europe/Budapest')))->toHaveCount(101);

    Http::assertSentCount(2);

    Inertia::version('after-deploy');

    expect(numbers($query->get('UTC')))->toHaveCount(102)->toContain(102, 2, 1);

    Http::assertSentCount(4);

    $query->get('UTC');

    Http::assertSentCount(4);
});

it('is null when GitHub cannot be reached, and asks again next time', function (mixed $response): void {
    Http::fake(['api.github.com/*' => Http::sequence()->pushResponse($response)->push([pullRequest(377, '2026-10-18T00:53:44Z')])]);

    $query = resolve(ChangelogQuery::class);

    expect($query->get('UTC'))->toBeNull()
        ->and(numbers($query->get('UTC')))->toBe([377]);
})->with([
    'an error' => fn () => Http::response(['message' => 'API rate limit exceeded'], 403),
    'a timeout' => fn () => Http::failedConnection(),
    'something unexpected' => fn () => Http::response(['message' => 'Not Found']),
]);
