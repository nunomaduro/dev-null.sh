<?php

declare(strict_types=1);

use App\Models\User;

it('may block a user', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create(['username' => 'octocat']);

    $user->following()->attach($blocked);
    $blocked->following()->attach($user);

    $response = $this->actingAs($user)
        ->fromRoute('user.show', $blocked)
        ->post(route('user-block.store', $blocked));

    $response->assertRedirectToRoute('user.show', $blocked)
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => "Blocked @octocat. Neither of you can message, follow, reply to, or star the other, or see each other's commits.",
        ]);

    expect($user->isBlocking($blocked))->toBeTrue()
        ->and($user->isFollowing($blocked))->toBeFalse()
        ->and($blocked->isFollowing($user))->toBeFalse();
});

it('may not block themselves', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('user-block.store', $user));

    $response->assertForbidden();

    expect($user->blocking()->count())->toBe(0);
});

it('may unblock a user', function (): void {
    $user = User::factory()->create();
    $blocked = User::factory()->create(['username' => 'octocat']);

    $user->blocking()->attach($blocked);

    $response = $this->actingAs($user)
        ->fromRoute('safety.edit')
        ->delete(route('user-block.destroy', $blocked));

    $response->assertRedirectToRoute('safety.edit')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unblocked @octocat.']);

    expect($user->isBlocking($blocked))->toBeFalse();
});

it('may not follow someone they block or who blocks them', function (bool $followerBlocks): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $followerBlocks ? $follower->blocking()->attach($user) : $user->blocking()->attach($follower);

    $response = $this->actingAs($follower)
        ->post(route('user-follow.store', $user));

    $response->assertForbidden();

    expect($follower->isFollowing($user))->toBeFalse()
        ->and($user->notifications()->exists())->toBeFalse();
})->with([true, false]);

it('requires authentication to block', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('user-block.store', $user));

    $response->assertRedirectToRoute('home');

    expect($user->blockers()->count())->toBe(0);
});
