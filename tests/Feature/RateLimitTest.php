<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;

dataset('rate limits', [
    'stars' => [
        fn (): array => [
            ['POST', route('commit-star.store', $commit = Commit::factory()->create())],
            ['DELETE', route('commit-star.destroy', $commit)],
        ],
        60,
        60,
    ],
    'bookmarks' => [
        fn (): array => [
            ['PUT', route('bookmark.store', $commit = Commit::factory()->create())],
            ['DELETE', route('bookmark.destroy', $commit)],
        ],
        60,
        60,
    ],
    'pins' => [
        fn (): array => [
            ['PUT', route('commit-pin.update', $commit = Commit::factory()->create())],
            ['DELETE', route('commit-pin.destroy', $commit)],
        ],
        20,
        60,
    ],
    'follows' => [
        fn (): array => [
            ['POST', route('user-follow.store', $user = User::factory()->create())],
            ['DELETE', route('user-follow.destroy', $user)],
        ],
        30,
        60,
    ],
    'people lookups' => [
        fn (): array => [
            ['GET', route('user-search.index', ['q' => 'nuno'])],
            ['GET', route('mention-suggestion.index', ['q' => 'nuno'])],
            ['GET', route('user-preview.show', User::factory()->create())],
            ['GET', route('mention.preview', User::factory()->create())],
        ],
        120,
        60,
    ],
    'exports' => [
        fn (): array => [
            ['GET', route('export')],
        ],
        3,
        3600,
    ],
]);

it('limits how often each user may hit an endpoint', function (Closure $endpoints, int $maxAttempts, int $decaySeconds): void {
    $user = User::factory()->create();
    $requests = $endpoints();

    $this->actingAs($user);

    foreach (range(1, $maxAttempts) as $attempt) {
        [$method, $uri] = $requests[$attempt % count($requests)];

        expect($this->json($method, $uri)->getStatusCode())->not->toBe(429);
    }

    foreach ($requests as [$method, $uri]) {
        $this->json($method, $uri)->assertTooManyRequests();
    }

    $this->travel($decaySeconds - 1)->seconds();

    $this->json(...$requests[0])->assertTooManyRequests();

    $this->travel(1)->seconds();

    expect($this->json(...$requests[0])->getStatusCode())->not->toBe(429);

    $this->actingAs(User::factory()->create());

    expect($this->json(...$requests[0])->getStatusCode())->not->toBe(429);
})->with('rate limits');

it('renders the too many requests page when exporting too often', function (): void {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 3) as $attempt) {
        $this->get(route('export'))->assertOk();
    }

    $this->get(route('export'))
        ->assertTooManyRequests()
        ->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('status', 429));
});

it('shares a people lookup limit per guest IP without limiting other visitors', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $search = route('user-search.index', ['q' => 'octo']);
    $preview = route('user-preview.show', $user);
    $mentionPreview = route('mention.preview', $user);
    $requests = [$search, $preview, $mentionPreview];

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);

    foreach (range(1, 120) as $attempt) {
        $this->getJson($requests[$attempt % count($requests)])->assertOk();
    }

    $this->getJson($search)->assertTooManyRequests();
    $this->getJson($preview)->assertTooManyRequests();
    $this->getJson($mentionPreview)->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);

    $this->getJson($search)->assertOk();
    $this->getJson($preview)->assertOk();
    $this->getJson($mentionPreview)->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
    $this->actingAs($user);

    $this->getJson($search)->assertOk();
    $this->getJson($preview)->assertOk();
    $this->getJson($mentionPreview)->assertOk();
});

it('shares the JSON star budget across commits and replies without consuming other action budgets', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();
    $ownCommit = Commit::factory()->for($user)->create();
    $requests = [
        ['POST', route('commit-star.store', $commit)],
        ['POST', route('commit-star.store', $reply)],
        ['DELETE', route('commit-star.destroy', $commit)],
        ['DELETE', route('commit-star.destroy', $reply)],
    ];

    $this->actingAs($user);

    foreach (range(0, 59) as $attempt) {
        [$method, $uri] = $requests[$attempt % count($requests)];
        $starred = $method === 'POST';

        $this->json($method, $uri)->assertOk()->assertExactJson([
            'starred' => $starred,
            'stars_count' => $starred ? 1 : 0,
        ]);
    }

    foreach ($requests as [$method, $uri]) {
        $this->json($method, $uri)->assertTooManyRequests()->assertHeader('Retry-After', '60');
    }

    expect($user->starredCommits()->count())->toBe(0);

    $this->putJson(route('bookmark.store', $commit))->assertOk();
    $this->put(route('commit-pin.update', $ownCommit))->assertRedirect();
    $this->post(route('user-follow.store', $commit->user))->assertRedirect();
    $this->getJson(route('user-search.index', ['q' => $commit->user->username]))->assertOk();
    $this->get(route('export'))->assertOk();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-star.store', $reply))
        ->assertOk()
        ->assertExactJson(['starred' => true, 'stars_count' => 1]);
});
