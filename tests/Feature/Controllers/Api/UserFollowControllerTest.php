<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('may follow a user', function (): void {
    $user = User::factory()->create();

    $response = signedApiRequest($this->keyPair, 'POST', route('api-user-follow.store', $user));

    $response->assertNoContent();

    expect($this->user->isFollowing($user))->toBeTrue()
        ->and($user->notifications()->sole()->type)->toBe(NotificationType::Followed);
});

it('may not follow themselves', function (): void {
    $response = signedApiRequest($this->keyPair, 'POST', route('api-user-follow.store', $this->user));

    $response->assertForbidden();

    expect($this->user->following()->count())->toBe(0);
});

it('may not follow anyone across a block, as on the web', function (Closure $block): void {
    $user = User::factory()->create();

    $block($this->user, $user);

    signedApiRequest($this->keyPair, 'POST', route('api-user-follow.store', $user))->assertForbidden();

    expect($this->user->isFollowing($user))->toBeFalse();
})->with([
    'blocking' => fn (User $follower, User $user) => $follower->blocking()->attach($user),
    'blocked' => fn (User $follower, User $user) => $user->blocking()->attach($follower),
]);

it('may unfollow a user', function (): void {
    $user = User::factory()->create();

    $this->user->following()->attach($user);

    $response = signedApiRequest($this->keyPair, 'DELETE', route('api-user-follow.destroy', $user));

    $response->assertNoContent();

    expect($this->user->isFollowing($user))->toBeFalse();
});

it('returns not found for unknown usernames', function (): void {
    $response = signedApiRequest($this->keyPair, 'POST', 'api/v1/users/missing/follow');

    $response->assertNotFound();
});

it('rejects requests that are not signed', function (string $method): void {
    $user = User::factory()->create();

    $this->json($method, route('api-user-follow.store', $user))->assertUnauthorized();

    expect($user->followers()->count())->toBe(0);
})->with(['POST', 'DELETE']);

it('limits follows and unfollows to 30 a minute, like the web, with a code', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 30) as $attempt) {
        signedApiRequest($this->keyPair, $attempt % 2 === 0 ? 'DELETE' : 'POST', route('api-user-follow.store', $user))->assertNoContent();
    }

    signedApiRequest($this->keyPair, 'POST', route('api-user-follow.store', $user))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertExactJson(['message' => 'Too Many Attempts.', 'code' => 'rate_limited']);
});
