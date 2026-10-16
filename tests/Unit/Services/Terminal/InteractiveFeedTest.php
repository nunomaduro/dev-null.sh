<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Services\Terminal\InteractiveFeed;

it('returns false when commit collection is empty', function (): void {
    expect(InteractiveFeed::run(collect(), 'everyone'))->toBeFalse();
});

it('gracefully handles non-TTY or missing extension environments without throwing', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $result = InteractiveFeed::run(collect([$commit]), 'everyone', interactive: false);

    expect($result)->toBeBool();
});
