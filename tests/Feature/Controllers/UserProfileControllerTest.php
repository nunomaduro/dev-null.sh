<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

it('renders profile settings page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('user-profile.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user-profile/edit'));
});

it('renders the chosen and automatic lanes', function (?int $preferredLane): void {
    $user = User::factory()->create(['id' => '3a788e93-4cf7-4819-9377-94be2f329563', 'preferred_lane' => $preferredLane]);

    $response = $this->actingAs($user)
        ->get(route('user-profile.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preferredLane', $preferredLane)
            ->where('automaticLane', 4)
            ->where('auth.user.lane', $preferredLane ?? 4));
})->with([null, 1]);

it('renders the custom color as the chosen lane', function (): void {
    $user = User::factory()->create(['lane_color' => '#1a2b3c']);

    $response = $this->actingAs($user)
        ->get(route('user-profile.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preferredLane', '#1a2b3c')
            ->where('auth.user.lane', '#1a2b3c'));
});

it('renders the activity graph links with the timezone preference', function (?string $timezone): void {
    $user = User::factory()->create(['username' => 'octocat', 'timezone' => $timezone]);

    $response = $this->actingAs($user)
        ->get(route('user-profile.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activityGraphUrl', url('/@octocat/graph.svg'))
            ->where('profileUrl', url('/@octocat'))
            ->where('auth.user.timezone', $timezone));
})->with([null, 'Europe/Budapest']);

it('redirects settings to profile settings', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/settings');

    $response->assertRedirectToRoute('user-profile.edit');
});

it('renders appearance settings page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('appearance.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appearance/update'));
});

it('renders terminal settings page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('terminal.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('terminal/show')
            ->where('url', url('/')));
});

it('requires authentication', function (string $route): void {
    $response = $this->get(route($route));

    $response->assertRedirectToRoute('home');
})->with(['user-profile.edit', 'appearance.edit', 'terminal.show']);

it('renders when the profile was last synced from github', function (?string $syncedAt): void {
    $user = User::factory()->create(['github_synced_at' => $syncedAt]);

    $response = $this->actingAs($user)
        ->get(route('user-profile.edit'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('githubSyncedAt', $syncedAt === null ? null : now()->parse($syncedAt)->toIso8601String()));
})->with([null, '2026-10-01 09:30:00']);

it('may sync the profile from github', function (): void {
    $user = User::factory()->create(['github_id' => '583231', 'username' => 'old-username', 'bio' => 'Old bio']);

    fakeGitHubAccount($user, ['login' => 'octocat', 'bio' => 'New bio']);

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'));

    $response->assertRedirectToRoute('user-profile.edit')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Synced from GitHub.',
        ]);

    expect($user->refresh())
        ->username->toBe('octocat')
        ->bio->toBe('New bio')
        ->github_synced_at->toDateTimeString()->toBe(now()->toDateTimeString());
});

it('tells the user when github could not be synced', function (): void {
    $user = User::factory()->create(['username' => 'old-username']);

    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response(['message' => 'Server Error'], 500),
    ]);

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'));

    $response->assertRedirectToRoute('user-profile.edit')
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Could not sync from GitHub. Try again in a minute.',
        ]);

    expect($user->refresh()->username)->toBe('old-username');
});

it('allows one sync a minute', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    fakeGitHubAccount($user);

    $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'));

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'));

    $response->assertRedirectToRoute('user-profile.edit')
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'You just synced. Try again in a minute.',
        ]);

    Http::assertSentCount(4);

    $this->travel(1)->minute();

    $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'))
        ->assertInertiaFlash('toast.type', 'success');

    fakeGitHubAccount($other, ['login' => 'hubot']);

    $this->actingAs($other)
        ->fromRoute('user-profile.edit')
        ->put(route('user-profile.update'))
        ->assertInertiaFlash('toast.type', 'success');
});

it('requires authentication to sync', function (): void {
    $response = $this->put(route('user-profile.update'));

    $response->assertRedirectToRoute('home');

    Http::assertNothingSent();
});
