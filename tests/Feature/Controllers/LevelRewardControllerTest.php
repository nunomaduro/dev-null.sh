<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\LevelRewardClaim;
use App\Models\User;
use App\Models\Wallet;

it('does not grant a reward before its level is reached', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('level-reward.store', ['level' => 100]))
        ->assertSessionHasErrors('reward');

    expect(LevelRewardClaim::query()->count())->toBe(0);
});

it('credits a milestone once, even if the same claim is replayed', function (): void {
    $user = User::factory()->create();

    foreach (range(0, 15) as $day) {
        Commit::factory()->for($user)->count(3)->create(['created_at' => now()->subDays($day)]);
    }

    $this->actingAs($user)->post(route('level-reward.store', ['level' => 5]))
        ->assertRedirect();
    $this->actingAs($user)->post(route('level-reward.store', ['level' => 5]))
        ->assertSessionHasErrors('reward');

    expect(LevelRewardClaim::query()->where('user_id', $user->id)->count())->toBe(1);
    /** @var Wallet $wallet */
    $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
    expect($wallet->balance)->toBe(25)
        ->and($wallet->lifetime_earned)->toBe(25);
});

it('requires authentication to claim level rewards', function (): void {
    $this->post(route('level-reward.store', ['level' => 5]))->assertRedirect();
    expect(LevelRewardClaim::query()->count())->toBe(0);
});
