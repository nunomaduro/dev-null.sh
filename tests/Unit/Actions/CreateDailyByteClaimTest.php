<?php

declare(strict_types=1);

use App\Actions\CreateDailyByteClaim;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

it('claims daily Bytes based on activity and streak', function (): void {
    $user = User::factory()->create([
        'created_at' => CarbonImmutable::now()->subDays(45),
    ]);

    Commit::factory()->for($user)->create([
        'created_at' => CarbonImmutable::today()->addHours(2),
    ]);

    $action = resolve(CreateDailyByteClaim::class);
    $claim = $action->handle($user);

    expect($claim->allocated_bytes)->toBeGreaterThanOrEqual(10);
    expect($claim->claimed_at)->not->toBeNull();

    $wallet = Wallet::query()->where('user_id', $user->id)->first();
    expect($wallet)->not->toBeNull();
    expect($wallet?->balance)->toBe($claim->allocated_bytes);
    expect($wallet?->lifetime_earned)->toBe($claim->allocated_bytes);
});

it('returns existing claim on double claiming in the same day', function (): void {
    $user = User::factory()->create();
    $action = resolve(CreateDailyByteClaim::class);

    $claim1 = $action->handle($user);
    $claim2 = $action->handle($user);

    expect($claim2->id)->toBe($claim1->id);
});

it('does not reward pending or rejected commits in the daily allocation', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(8)->create([
        'created_at' => CarbonImmutable::today()->addHour(),
        'status' => CommitStatus::Pending,
    ]);
    Commit::factory()->for($user)->count(8)->create([
        'created_at' => CarbonImmutable::today()->addHour(),
        'status' => CommitStatus::Rejected,
    ]);

    $claim = resolve(CreateDailyByteClaim::class)->handle($user);

    expect($claim->allocated_bytes)->toBe(10)
        ->and($claim->activity_score)->toBe(10);
});
