<?php

declare(strict_types=1);

use App\Actions\UpdateShadow;
use App\Models\User;

it('updates shadow name and glyph cleanly', function (): void {
    $user = User::factory()->create();
    $action = resolve(UpdateShadow::class);

    $shadow = $action->handle($user, 'matrix-daemon', '❖', 'classic-green');

    expect($shadow->name)->toBe('matrix-daemon');
    expect($shadow->glyph)->toBe('❖');
    expect($shadow->skin)->toBe('classic-green');
});

it('rejects unequipped locked skin', function (): void {
    $user = User::factory()->create();
    $action = resolve(UpdateShadow::class);

    $action->handle($user, skin: 'cyber-cyan');
})->throws(InvalidArgumentException::class, "Skin 'cyber-cyan' has not been unlocked yet.");
