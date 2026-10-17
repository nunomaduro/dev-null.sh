<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores a trimmed giphy key encrypted at rest', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->fromRoute('user-profile.edit')
        ->put(route('giphy-key.update'), ['key' => '  giphy-test-key  '])
        ->assertRedirectToRoute('user-profile.edit')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'GIPHY key connected.',
        ]);

    expect($user->refresh()->giphy_key)->toBe('giphy-test-key')
        ->and(DB::table('users')->where('id', $user->id)->value('giphy_key'))
        ->not->toContain('giphy-test-key');
});

it('replaces an existing key', function (): void {
    $user = User::factory()->withGiphyKey('first-key')->create();

    $this->actingAs($user)->fromRoute('user-profile.edit')
        ->put(route('giphy-key.update'), ['key' => 'second-key'])
        ->assertRedirectToRoute('user-profile.edit');

    expect($user->refresh()->giphy_key)->toBe('second-key');
});

it('removes the key', function (): void {
    $user = User::factory()->withGiphyKey()->create();

    $this->actingAs($user)->fromRoute('user-profile.edit')
        ->delete(route('giphy-key.destroy'))
        ->assertRedirectToRoute('user-profile.edit')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'GIPHY key removed.',
        ]);

    expect($user->refresh()->giphy_key)->toBeNull();
});

it('rejects invalid keys without touching the stored one', function (array $payload): void {
    $user = User::factory()->withGiphyKey('stored-key')->create();

    $this->actingAs($user)->putJson(route('giphy-key.update'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('key');

    expect($user->refresh()->giphy_key)->toBe('stored-key');
})->with([
    'missing' => [fn (): array => []],
    'empty' => [fn (): array => ['key' => '']],
    'whitespace' => [fn (): array => ['key' => '   ']],
    'not a string' => [fn (): array => ['key' => ['nested']]],
    'too long' => [fn (): array => ['key' => str_repeat('a', 256)]],
]);

it('requires authentication to connect a key', function (): void {
    $this->putJson(route('giphy-key.update'), ['key' => 'giphy-test-key'])->assertUnauthorized();
});

it('requires authentication to remove a key', function (): void {
    $this->deleteJson(route('giphy-key.destroy'))->assertUnauthorized();
});
