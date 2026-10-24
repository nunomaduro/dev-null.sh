<?php

declare(strict_types=1);

use App\Enums\Log;
use App\Models\User;

it('lands on the log with the shared text flashed for the composer', function (): void {
    $user = User::factory()->create(['log' => Log::Latest]);

    $this->actingAs($user)
        ->get(route('commit-intent.show', ['text' => '  Shipped https://example.com  ']))
        ->assertRedirectToRoute('log.show', Log::Latest)
        ->assertInertiaFlash('intent', 'Shipped https://example.com');

    expect($user->commits()->exists())->toBeFalse();
});

it('flashes nothing without text', function (string $query): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('commit-intent.show').$query)
        ->assertRedirectToRoute('log.show', $user->log)
        ->assertInertiaFlashMissing('intent');
})->with(['', '?text=', '?text=%20%20']);

it('sends guests to sign in first, then back to share', function (): void {
    $this->get(route('commit-intent.show', ['text' => 'Hello']))
        ->assertRedirectToRoute('home');

    expect(session('url.intended'))->toBe(route('commit-intent.show', ['text' => 'Hello']));
});
