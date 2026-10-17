<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;

it('unlocks shadow skin via controller', function (): void {
    $user = User::factory()->create();
    Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 100,
    ]);

    $response = $this->actingAs($user)
        ->post(route('shadow-skin.store', 'amber-crt'));

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($user->wallet?->balance)->toBe(50)
        ->and($user->shadow?->unlocked_skins)->toBe(['classic-green', 'amber-crt']);
});

it('returns a validation error when there are not enough Bytes for the skin', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('shadow-skin.store', 'kernel-red'))
        ->assertRedirect()
        ->assertSessionHasErrors(['skin']);
});
