<?php

declare(strict_types=1);

use App\Actions\UpdateShadowRewardSkin;
use App\Models\Shadow;
use App\Models\User;

it('does not create a shadow when a level reward has no cosmetic skin', function (): void {
    $user = User::factory()->create();
    resolve(UpdateShadowRewardSkin::class)->handle($user, null);

    expect(Shadow::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('grants the free skin and creates a default shadow if the user has none', function (): void {
    $user = User::factory()->create();
    resolve(UpdateShadowRewardSkin::class)->handle($user, 'matrix');

    $shadow = Shadow::query()->where('user_id', $user->id)->firstOrFail();

    expect($shadow->skin)->toBe('classic-green')
        ->and($shadow->unlocked_skins)->toBe(['classic-green', 'matrix']);
});

it('preserves existing cosmetics without duplicating an already unlocked skin', function (): void {
    $user = User::factory()->create();
    $shadow = Shadow::factory()->create([
        'user_id' => $user->id,
        'name' => 'my-daemon',
        'unlocked_skins' => ['classic-green', 'matrix'],
    ]);

    resolve(UpdateShadowRewardSkin::class)->handle($user, 'matrix');
    $shadow->refresh();

    expect($shadow->name)->toBe('my-daemon')
        ->and($shadow->unlocked_skins)->toBe(['classic-green', 'matrix']);
});
