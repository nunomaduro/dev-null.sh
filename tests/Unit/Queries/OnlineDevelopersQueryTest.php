<?php

declare(strict_types=1);

use App\Models\User;
use App\Queries\OnlineDevelopersQuery;
use Illuminate\Support\Facades\Cache;

it('counts developers active within the online window', function (): void {
    Cache::flush();

    User::factory()->create(['last_active_at' => now()->subMinutes(1)]);
    User::factory()->create(['last_active_at' => now()->subMinutes(3)]);
    User::factory()->create(['last_active_at' => now()->subMinutes(4)->subSeconds(30)]);

    // Inactive / beyond window
    User::factory()->create(['last_active_at' => now()->subMinutes(6)]);
    User::factory()->create(['last_active_at' => now()->subHour()]);
    User::factory()->create(['last_active_at' => null]);

    $count = resolve(OnlineDevelopersQuery::class)->count();

    expect($count)->toBe(3);
});

it('returns zero when no developers are online', function (): void {
    Cache::flush();

    User::factory()->create(['last_active_at' => now()->subMinutes(10)]);
    User::factory()->create(['last_active_at' => null]);

    $count = resolve(OnlineDevelopersQuery::class)->count();

    expect($count)->toBe(0);
});

it('caches the count for the configured ttl', function (): void {
    Cache::flush();

    User::factory()->create(['last_active_at' => now()->subMinute()]);

    $query = resolve(OnlineDevelopersQuery::class);

    expect($query->count())->toBe(1);

    // Another user becomes active, but cached count remains
    User::factory()->create(['last_active_at' => now()]);

    expect($query->count())->toBe(1);

    // After clearing cache, new count is retrieved
    Cache::flush();

    expect($query->count())->toBe(2);
});
