<?php

declare(strict_types=1);

use App\Models\User;

it('claims daily byte allocation successfully', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('wallet-claim.store'));

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($user->dailyEpochClaims()->count())->toBe(1)
        ->and($user->wallet?->balance)->toBeGreaterThanOrEqual(10);
});
