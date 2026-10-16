<?php

declare(strict_types=1);

use App\Actions\CreateTomFriendship;
use App\Actions\CreateWorldReply;
use App\Models\Commit;
use App\Models\User;
use App\Queries\UserShiptalkerIndexQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.github.token' => 'github-token']);
});

it('compares the GitHub contributions with the approved commits and replies since the user joined', function (): void {
    $this->travelTo('2026-10-16 12:00:00');
    $user = User::factory()->create(['created_at' => '2026-10-15 09:30:00']);

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create(['parent_id' => Commit::factory()->create()->id]);
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();
    Commit::factory()->for($user)->create()->delete();
    fakeGitHubContributions($user, 42);

    $index = resolve(UserShiptalkerIndexQuery::class)->get($user);

    expect($index)->toBe(['shipped' => 42, 'talked' => 2, 'since' => '2026-10-15T09:30:00+00:00']);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer github-token')
        && $request['variables'] === ['login' => $user->username, 'from' => '2026-10-15T09:30:00+00:00']);
});

it('compares only the last year for users who joined earlier', function (): void {
    $this->travelTo('2026-10-16 12:00:00');
    $user = User::factory()->create(['created_at' => '2024-01-01 00:00:00']);

    Commit::factory()->for($user)->create(['created_at' => '2025-10-16 11:59:59']);
    Commit::factory()->for($user)->create(['created_at' => '2025-10-16 12:00:00']);
    fakeGitHubContributions($user, 7);

    $index = resolve(UserShiptalkerIndexQuery::class)->get($user);

    expect($index)->toBe(['shipped' => 7, 'talked' => 1, 'since' => '2025-10-16T12:00:00+00:00']);
});

it('caches the contributions for an hour', function (): void {
    $user = User::factory()->create();
    fakeGitHubContributions($user, 3);
    $query = resolve(UserShiptalkerIndexQuery::class);

    $query->get($user);
    $query->get($user);

    Http::assertSentCount(1);

    $this->travel(3601)->seconds();
    $query->get($user);

    Http::assertSentCount(2);
});

it('is null without a token, without asking GitHub', function (): void {
    config(['services.github.token' => null]);
    $user = User::factory()->create();
    fakeGitHubContributions($user, 1);
    $query = resolve(UserShiptalkerIndexQuery::class);

    expect($query->isConfigured())->toBeFalse()
        ->and($query->get($user))->toBeNull();

    Http::assertNothingSent();
});

it('counts the bots as all talk, without asking GitHub', function (string $githubId): void {
    $this->travelTo('2026-10-16 12:00:00');
    $bot = User::factory()->create(['github_id' => $githubId, 'created_at' => '2026-10-15 09:30:00']);
    Commit::factory()->for($bot)->create();
    fakeGitHubContributions($bot, 1);

    $index = resolve(UserShiptalkerIndexQuery::class)->get($bot);

    expect($index)->toBe(['shipped' => 0, 'talked' => 1, 'since' => '2026-10-15T09:30:00+00:00']);

    Http::assertNothingSent();
})->with([
    'Tom' => CreateTomFriendship::GITHUB_ID,
    '@world' => CreateWorldReply::GITHUB_ID,
]);

it('is null when GitHub fails or the login now belongs to another account', function (mixed $response): void {
    $user = User::factory()->create(['github_id' => '583231']);
    Http::fake(['api.github.com/graphql' => $response]);

    expect(resolve(UserShiptalkerIndexQuery::class)->get($user))->toBeNull();
})->with([
    'an error' => fn () => Http::response(['message' => 'Bad credentials'], 401),
    'a timeout' => fn () => Http::failedConnection(),
    'no such user' => fn () => Http::response(['data' => ['user' => null], 'errors' => [['type' => 'NOT_FOUND']]]),
    'another account' => fn () => Http::response(['data' => ['user' => [
        'databaseId' => 1,
        'contributionsCollection' => ['contributionCalendar' => ['totalContributions' => 5]],
    ]]]),
]);
