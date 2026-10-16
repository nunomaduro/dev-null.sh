<?php

declare(strict_types=1);

use App\Actions\UpdateShadowSkin;
use App\Models\User;
use App\Models\Wallet;

it('unlocks a skin using Byte tokens', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 100,
    ]);

    $action = resolve(UpdateShadowSkin::class);
    $shadow = $action->handle($user, 'amber-crt');

    expect($shadow->unlocked_skins)->toContain('amber-crt');

    $wallet->refresh();
    expect($wallet->balance)->toBe(50);
});

it('ignores unknown skins', function (): void {
    $user = User::factory()->create();
    $action = resolve(UpdateShadowSkin::class);

    $shadow = $action->handle($user, 'hacker-god-rainbow');
    expect($shadow->unlocked_skins)->not->toContain('hacker-god-rainbow');
});

it('ignores unlocking with insufficient balance', function (): void {
    $user = User::factory()->create();
    Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 20,
    ]);

    $action = resolve(UpdateShadowSkin::class);
    $shadow = $action->handle($user, 'amber-crt');
    expect($shadow->unlocked_skins)->not->toContain('amber-crt');
});

it('returns early when skin is already unlocked', function (): void {
    $user = User::factory()->create();
    $action = resolve(UpdateShadowSkin::class);

    $shadow = $action->handle($user, 'classic-green');
    expect($shadow->unlocked_skins)->toBe(['classic-green']);
});
