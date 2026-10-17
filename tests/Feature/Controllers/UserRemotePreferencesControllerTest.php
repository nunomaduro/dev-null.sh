<?php

declare(strict_types=1);

use App\Enums\RemotesFrom;
use App\Models\User;

it('saves who can push and whether pulls are shared', function (): void {
    $mark = User::factory()->withRemotes(RemotesFrom::Following)->create();

    $this->actingAs($mark)->fromRoute('conversation.index')
        ->put(route('user-remote-preferences.update'), ['from' => 'everyone', 'pull_receipts' => false])
        ->assertRedirectToRoute('conversation.index');

    expect($mark->refresh()->remotes_from)->toBe(RemotesFrom::Everyone)
        ->and($mark->pull_receipts)->toBeFalse();
});

it('rejects invalid preferences', function (array $payload, string $error): void {
    $this->actingAs(User::factory()->withRemotes()->create())->putJson(route('user-remote-preferences.update'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [fn (): array => ['from' => 'nobody', 'pull_receipts' => true], 'from'],
    [fn (): array => ['from' => 'everyone'], 'pull_receipts'],
]);

it('is only for people with remotes on', function (): void {
    $this->actingAs(User::factory()->create())->putJson(route('user-remote-preferences.update'), ['from' => 'everyone', 'pull_receipts' => true])
        ->assertNotFound();
});
