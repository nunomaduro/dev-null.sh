<?php

declare(strict_types=1);

use App\Models\DailyEpochClaim;
use App\Models\User;
use Carbon\CarbonImmutable;

it('creates and manages a daily epoch claim', function (): void {
    $user = User::factory()->create();
    $today = CarbonImmutable::today();

    $claim = DailyEpochClaim::factory()->create([
        'user_id' => $user->id,
        'epoch_date' => $today->toDateString(),
        'activity_score' => 80,
        'allocated_bytes' => 40,
        'claimed_at' => $today->addHours(5),
    ]);

    expect($claim->user->id)->toBe($user->id);
    expect($claim->epoch_date)->toBe($today->toDateString());
    expect($claim->activity_score)->toBe(80);
    expect($claim->allocated_bytes)->toBe(40);
    expect($claim->claimed_at)->not->toBeNull();
    expect($user->dailyEpochClaims)->toHaveCount(1);
});
