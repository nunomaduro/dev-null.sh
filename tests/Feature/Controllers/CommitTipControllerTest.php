<?php

declare(strict_types=1);

use App\Enums\CommitGift;
use App\Models\Commit;
use App\Models\User;
use App\Models\Wallet;

it('successfully tips a commit with Bytes', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    Wallet::factory()->create([
        'user_id' => $sender->id,
        'balance' => 100,
    ]);

    $response = $this->actingAs($sender)
        ->post(route('commit-tip.store', $commit), [
            'gift' => CommitGift::Coffee->value,
            'message' => 'Great work!',
        ]);

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($commit->tips)->toHaveCount(1);
});

it('forbids tipping own commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('commit-tip.store', $commit), [
            'gift' => CommitGift::Coffee->value,
        ])
        ->assertForbidden();
});

it('validates gift presence and validity', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    $this->actingAs($sender)
        ->postJson(route('commit-tip.store', $commit), [
            'gift' => 'invalid-gift',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['gift']);
});

it('returns 422 if sender has insufficient bytes balance', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    Wallet::factory()->create([
        'user_id' => $sender->id,
        'balance' => 0,
    ]);

    $this->actingAs($sender)
        ->post(route('commit-tip.store', $commit), [
            'gift' => CommitGift::Coffee->value,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['gift']);
});
