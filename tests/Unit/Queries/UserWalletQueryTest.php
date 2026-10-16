<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;
use App\Queries\UserWalletQuery;

it('queries user wallet data and claim status', function (): void {
    $user = User::factory()->create();
    Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 150,
        'lifetime_earned' => 200,
        'lifetime_tipped' => 50,
    ]);

    $query = resolve(UserWalletQuery::class);
    $data = $query->get($user);

    expect($data['balance'])->toBe(150);
    expect($data['lifetime_earned'])->toBe(200);
    expect($data['lifetime_tipped'])->toBe(50);
    expect($data['has_claimed_today'])->toBeFalse();
});
