<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('may dismiss a suggested user', function (): void {
    $dismissed = User::factory()->create();

    signedApiRequest($this->keyPair, 'POST', route('api-suggestion-dismissal.store', $dismissed))->assertNoContent();

    expect($this->user->dismissedSuggestions()->pluck('users.id')->all())->toBe([$dismissed->id]);
});

it('keeps one dismissal when a user is dismissed again', function (): void {
    $dismissed = User::factory()->create();

    $this->user->dismissedSuggestions()->attach($dismissed);

    signedApiRequest($this->keyPair, 'POST', route('api-suggestion-dismissal.store', $dismissed))->assertNoContent();

    expect($this->user->dismissedSuggestions()->count())->toBe(1);
});

it('neither mutes nor unfollows who it dismisses', function (): void {
    $dismissed = User::factory()->create();

    $this->user->following()->attach($dismissed);

    signedApiRequest($this->keyPair, 'POST', route('api-suggestion-dismissal.store', $dismissed))->assertNoContent();

    expect($this->user->isFollowing($dismissed))->toBeTrue()
        ->and($this->user->isMuting($dismissed))->toBeFalse();
});

it('may not dismiss themselves', function (): void {
    signedApiRequest($this->keyPair, 'POST', route('api-suggestion-dismissal.store', $this->user))->assertForbidden();

    expect($this->user->dismissedSuggestions()->count())->toBe(0);
});

it('returns not found for unknown usernames', function (): void {
    signedApiRequest($this->keyPair, 'POST', 'api/v1/users/missing/dismiss')->assertNotFound();
});

it('requires a signed request', function (): void {
    $dismissed = User::factory()->create();

    $this->postJson(route('api-suggestion-dismissal.store', $dismissed))->assertUnauthorized();
});
