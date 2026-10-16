<?php

declare(strict_types=1);

use App\Models\Shadow;
use App\Models\User;

it('creates and manages a shadow companion', function (): void {
    $user = User::factory()->create();
    $shadow = Shadow::factory()->create([
        'user_id' => $user->id,
        'name' => 'cron-master',
        'glyph' => '◈',
        'skin' => 'amber-crt',
        'title' => 'Cron Supervisor',
        'unlocked_skins' => ['classic-green', 'amber-crt'],
    ]);

    expect($shadow->user->id)->toBe($user->id);
    expect($shadow->name)->toBe('cron-master');
    expect($shadow->glyph)->toBe('◈');
    expect($shadow->skin)->toBe('amber-crt');
    expect($shadow->title)->toBe('Cron Supervisor');
    expect($shadow->unlocked_skins)->toBe(['classic-green', 'amber-crt']);
    expect($user->shadow?->id)->toBe($shadow->id);
});
