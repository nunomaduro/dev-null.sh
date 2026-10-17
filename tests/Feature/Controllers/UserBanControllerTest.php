<?php

declare(strict_types=1);

use App\Models\Ban;
use App\Models\Commit;
use App\Models\User;

beforeEach(function (): void {
    config(['app.admins' => ['moderator', 'other-moderator']]);
});

it('may ban a user as a moderator, hiding them and their commits without deleting anything', function (): void {
    $moderator = User::factory()->create(['username' => 'moderator']);
    $user = User::factory()->create(['github_id' => '583231', 'username' => 'octocat']);
    $commit = Commit::factory()->for($user)->create();

    $response = $this->actingAs($moderator)
        ->fromRoute('user.show', $user)
        ->post(route('user-ban.store', $user));

    $response->assertRedirectToRoute('reported-commit.index')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Banned @octocat. Their profile, commits and replies are hidden, and they can no longer sign in.',
        ]);

    expect(Ban::query()->sole()->github_id)->toBe('583231')
        ->and(User::query()->find($user->id))->toBeNull()
        ->and(Commit::query()->find($commit->id))->toBeNull()
        ->and(User::query()->withoutGlobalScopes()->find($user->id))->not->toBeNull()
        ->and(Commit::query()->withoutGlobalScopes()->find($commit->id))->not->toBeNull();

    $this->get(route('user.show', $user))->assertNotFound();
    $this->get(route('commit.show', [$user, $commit]))->assertNotFound();
});

it('may not ban without being a moderator', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('user-ban.store', $user));

    $response->assertForbidden();

    expect(Ban::query()->exists())->toBeFalse();
});

it('may not ban a moderator, themselves included', function (string $username): void {
    $moderator = User::factory()->create(['username' => 'moderator']);
    $banned = $username === 'moderator' ? $moderator : User::factory()->create(['username' => $username]);

    $response = $this->actingAs($moderator)
        ->post(route('user-ban.store', $banned));

    $response->assertForbidden();

    expect(Ban::query()->exists())->toBeFalse();
})->with(['moderator', 'other-moderator']);

it('requires authentication to ban', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('user-ban.store', $user));

    $response->assertRedirectToRoute('home');

    expect(Ban::query()->exists())->toBeFalse();
});
