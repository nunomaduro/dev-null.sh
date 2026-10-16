<?php

declare(strict_types=1);

use App\Models\User;

it('updates shadow cosmetics via controller', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->patch(route('shadow.update'), [
            'name' => 'cyber-daemon',
            'glyph' => '⚡',
            'skin' => 'classic-green',
        ]);

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($user->shadow?->name)->toBe('cyber-daemon')
        ->and($user->shadow?->glyph)->toBe('⚡')
        ->and($user->shadow?->skin)->toBe('classic-green');
});

it('rejects equipping a locked Shadow skin without a server error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shadow.update'), ['skin' => 'kernel-red'])
        ->assertRedirect()
        ->assertSessionHasErrors(['skin']);
});
