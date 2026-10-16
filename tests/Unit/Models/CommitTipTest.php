<?php

declare(strict_types=1);

use App\Enums\CommitGift;
use App\Models\Commit;
use App\Models\CommitTip;
use App\Models\User;

it('creates and manages a commit tip', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $commit = Commit::factory()->for($recipient)->create();

    $tip = CommitTip::factory()->create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'commit_id' => $commit->id,
        'amount' => 50,
        'gift_type' => CommitGift::Overclock,
        'message' => 'Great refactoring!',
    ]);

    expect($tip->sender->id)->toBe($sender->id);
    expect($tip->recipient->id)->toBe($recipient->id);
    expect($tip->commit->id)->toBe($commit->id);
    expect($tip->amount)->toBe(50);
    expect($tip->gift_type)->toBe(CommitGift::Overclock);
    expect($tip->message)->toBe('Great refactoring!');
    expect($sender->sentTips)->toHaveCount(1);
    expect($recipient->receivedTips)->toHaveCount(1);
    expect($commit->tips)->toHaveCount(1);
});
