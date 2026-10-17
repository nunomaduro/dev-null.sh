<?php

declare(strict_types=1);

use App\Models\Shadow;
use App\Models\User;
use App\Queries\UserShadowQuery;

it('queries user shadow profile and computes rank aura', function (): void {
    $user = User::factory()->create();
    Shadow::factory()->create([
        'user_id' => $user->id,
        'name' => 'sys-shadow',
        'glyph' => '⚡',
        'skin' => 'classic-green',
    ]);

    $query = resolve(UserShadowQuery::class);
    $data = $query->get($user, $user);

    expect($data['name'])->toBe('sys-shadow');
    expect($data['glyph'])->toBe('⚡');
    expect($data['skin'])->toBe('classic-green');
    expect($data['aura_color'])->toBe('gray');
});

it('returns default fallback shadow when record does not exist', function (): void {
    $user = User::factory()->create();

    $query = resolve(UserShadowQuery::class);
    $data = $query->get($user, $user);

    expect($data['name'])->toBe('daemon-0');
    expect($data['glyph'])->toBe('λ');
    expect($data['skin'])->toBe('classic-green');
    expect($data['unlocked_skins'])->toBe(['classic-green']);
    expect($data['title'])->toBe('PID 1024');
});
