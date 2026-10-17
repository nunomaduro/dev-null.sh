<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;

it('creates and manages a user wallet', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 200,
        'lifetime_earned' => 250,
        'lifetime_tipped' => 50,
    ]);

    expect($wallet->user->id)->toBe($user->id);
    expect($wallet->balance)->toBe(200);
    expect($wallet->lifetime_earned)->toBe(250);
    expect($wallet->lifetime_tipped)->toBe(50);
    expect($user->wallet?->id)->toBe($wallet->id);
});
