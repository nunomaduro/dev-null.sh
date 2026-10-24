<?php

declare(strict_types=1);

use App\Queries\ContributorsQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('lists everyone with a commit, as GitHub counts them', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187), gitHubContributor('jubianchi', 1)]);

    expect(resolve(ContributorsQuery::class)->get())->toBe([
        ['login' => 'xHeaven', 'commits' => 187, 'username' => null],
        ['login' => 'jubianchi', 'commits' => 1, 'username' => null],
    ]);
});

it('leaves out the bots and the agents, whatever case they are written in', function (): void {
    fakeGitHubContributors([
        gitHubContributor('xHeaven', 187),
        gitHubContributor('ampagent', 8),
        gitHubContributor('Claude', 2),
        gitHubContributor('dependabot', 4, 'Bot'),
    ]);

    expect(resolve(ContributorsQuery::class)->get())->toBe([['login' => 'xHeaven', 'commits' => 187, 'username' => null]]);
});

it('reads every page GitHub has, up to a thousand people', function (int $contributors, int $expected, int $requests): void {
    fakeGitHubContributors(array_map(fn (int $number): array => gitHubContributor("dev{$number}", 2000 - $number), range(1, $contributors)));

    expect(resolve(ContributorsQuery::class)->get())->toHaveCount($expected);

    Http::assertSentCount($requests);
})->with([
    'one page' => [100, 100, 2],
    'two pages' => [150, 150, 2],
    'more than ten pages' => [1200, 1000, 10],
]);

it('keeps reading when a page is full but some of it is left out', function (): void {
    $page = array_map(fn (int $number): array => gitHubContributor("dev{$number}", 200 - $number), range(1, 99));

    fakeGitHubContributors([...$page, gitHubContributor('dependabot', 4, 'Bot'), gitHubContributor('xHeaven', 1)]);

    expect(resolve(ContributorsQuery::class)->get())->toHaveCount(100);

    Http::assertSentCount(2);
});

it('skips what GitHub sends without a login, an account or a count', function (): void {
    fakeGitHubContributors([
        ['login' => 'ghost', 'id' => 1, 'type' => 'User'],
        ['id' => 2, 'contributions' => 3, 'type' => 'User'],
        ['login' => 'nobody', 'contributions' => 3, 'type' => 'User'],
        gitHubContributor('xHeaven', 187),
    ]);

    expect(resolve(ContributorsQuery::class)->get())->toBe([['login' => 'xHeaven', 'commits' => 187, 'username' => null]]);
});

it('asks GitHub again an hour later, not before', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187)]);

    resolve(ContributorsQuery::class)->get();
    $this->travel(59)->minutes();
    resolve(ContributorsQuery::class)->get();

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    resolve(ContributorsQuery::class)->get();

    Http::assertSentCount(2);
});

it('keeps an answer with nobody in it for the hour, since GitHub did answer', function (): void {
    fakeGitHubContributors([gitHubContributor('claude', 2)]);

    expect(resolve(ContributorsQuery::class)->get())->toBe([])
        ->and(Cache::get('about:contributor-accounts:'.config()->string('services.github.repository')))->toBe([]);

    $this->travel(2)->minutes();
    resolve(ContributorsQuery::class)->get();

    Http::assertSentCount(1);
});

it('has nobody to show while GitHub is unreachable, and tries again a minute later', function (): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('timeout');
    });

    expect(resolve(ContributorsQuery::class)->get())->toBeNull();

    resolve(ContributorsQuery::class)->get();
    expect($attempts)->toBe(1);

    $this->travel(61)->seconds();
    resolve(ContributorsQuery::class)->get();

    expect($attempts)->toBe(2);
});

it('has nobody to show when GitHub answers with an error or with something that is not a list', function (array $body, int $status): void {
    Http::fake(['api.github.com/*' => Http::response($body, $status)]);

    expect(resolve(ContributorsQuery::class)->get())->toBeNull();
})->with([
    'an error' => [['message' => 'Not Found'], 404],
    'an object' => [['message' => 'Moved'], 200],
]);

it('discards a partial list after a later page fails and recovers when the failure expires', function (): void {
    $firstPage = array_map(fn (int $number): array => gitHubContributor("dev{$number}", 200 - $number), range(1, 100));
    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => Http::sequence()
        ->push($firstPage)
        ->pushStatus(503)
        ->push([gitHubContributor('recovered', 12)])]);

    expect(resolve(ContributorsQuery::class)->get())->toBeNull();
    expect(resolve(ContributorsQuery::class)->get())->toBeNull();
    Http::assertSentCount(2);

    $this->travel(61)->seconds();

    expect(resolve(ContributorsQuery::class)->get())->toBe([['login' => 'recovered', 'commits' => 12, 'username' => null]]);
    Http::assertSentCount(3);
});
