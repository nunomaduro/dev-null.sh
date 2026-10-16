<?php

declare(strict_types=1);

use App\Actions\CreateCommitTip;
use App\Enums\CommitGift;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;

it('successfully transfers Bytes and creates a tip with notification', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    $senderWallet = Wallet::factory()->create([
        'user_id' => $sender->id,
        'balance' => 100,
        'lifetime_earned' => 100,
        'lifetime_tipped' => 0,
    ]);

    $recipientWallet = Wallet::factory()->create([
        'user_id' => $recipient->id,
        'balance' => 0,
        'lifetime_earned' => 0,
        'lifetime_tipped' => 0,
    ]);

    $action = resolve(CreateCommitTip::class);
    $tip = $action->handle($sender, $commit, CommitGift::Coffee, 'Thanks for the fix!');

    expect($tip)->not->toBeNull();
    expect($tip?->amount)->toBe(10);
    expect($tip?->gift_type)->toBe(CommitGift::Coffee);
    expect($tip?->message)->toBe('Thanks for the fix!');

    $senderWallet->refresh();
    $recipientWallet->refresh();

    expect($senderWallet->balance)->toBe(90);
    expect($senderWallet->lifetime_tipped)->toBe(10);
    expect($recipientWallet->balance)->toBe(10);
    expect($recipientWallet->lifetime_earned)->toBe(10);

    $notification = Notification::query()->where('recipient_id', $recipient->id)->first();
    expect($notification)->not->toBeNull();
    expect($notification?->type)->toBe(NotificationType::Tipped);
});

it('rejects tipping own commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    $action = resolve(CreateCommitTip::class);
    $tip = $action->handle($user, $commit, CommitGift::Coffee);

    expect($tip)->toBeNull();
});

it('rejects tipping anonymous commit without user', function (): void {
    $sender = User::factory()->create();
    $commit = Commit::factory()->create(['user_id' => null]);

    $action = resolve(CreateCommitTip::class);
    $tip = $action->handle($sender, $commit, CommitGift::Coffee);

    expect($tip)->toBeNull();
});

it('rejects tipping with insufficient balance', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    Wallet::factory()->create([
        'user_id' => $sender->id,
        'balance' => 5,
    ]);

    $action = resolve(CreateCommitTip::class);
    $tip = $action->handle($sender, $commit, CommitGift::Coffee);

    expect($tip)->toBeNull();
});
