<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

it('updates last_active_at on request when authenticated', function (): void {
    Carbon::setTestNow(now());
    Cache::flush();

    $user = User::factory()->create(['last_active_at' => null]);

    $this->actingAs($user)->get('/log/following')->assertOk();

    expect($user->fresh()->last_active_at?->timestamp)->toBe(now()->timestamp);
});

it('throttles rapid last_active_at updates within one minute', function (): void {
    $initialTime = now()->subMinutes(10);
    $user = User::factory()->create(['last_active_at' => $initialTime]);

    Carbon::setTestNow(now());
    $this->actingAs($user)->get('/log/following')->assertOk();

    $firstUpdate = $user->fresh()->last_active_at;
    expect($firstUpdate?->timestamp)->toBe(now()->timestamp);

    // Another request 30 seconds later
    Carbon::setTestNow(now()->addSeconds(30));
    $this->actingAs($user)->get('/log/following')->assertOk();

    // Should not have updated timestamp again
    expect($user->fresh()->last_active_at?->timestamp)->toBe($firstUpdate->timestamp);

    // Request after 1 minute passes
    Carbon::setTestNow(now()->addSeconds(65));
    $this->actingAs($user)->get('/log/following')->assertOk();

    expect($user->fresh()->last_active_at?->timestamp)->toBe(now()->timestamp);
});

it('shares onlineDevelopersCount in inertia props', function (): void {
    Cache::flush();

    User::factory()->create(['last_active_at' => now()->subMinutes(2)]);
    User::factory()->create(['last_active_at' => now()->subMinutes(3)]);
    $viewer = User::factory()->create(['last_active_at' => null]);

    $response = $this->actingAs($viewer)->get('/log/following');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('onlineDevelopersCount')
            ->where('onlineDevelopersCount', 3));
});
