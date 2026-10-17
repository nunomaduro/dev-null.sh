<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\PinnedCommitQuery;

it('finds the pinned commit when the viewer may see it', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->pending()->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    expect(resolve(PinnedCommitQuery::class)->get($user, $user)?->id)->toBe($pinned->id)
        ->and(resolve(PinnedCommitQuery::class)->get($user, User::factory()->create()))->toBeNull()
        ->and(resolve(PinnedCommitQuery::class)->get(User::factory()->create(), $user))->toBeNull();
});

it('only finds an approved pinned commit for a guest', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    $author = User::factory()->create();
    $pendingPinned = Commit::factory()->for($author)->pending()->create();
    $author->update(['pinned_commit_id' => $pendingPinned->id]);

    expect(resolve(PinnedCommitQuery::class)->get($user, null)?->id)->toBe($pinned->id)
        ->and(resolve(PinnedCommitQuery::class)->get($author, null))->toBeNull();
});
