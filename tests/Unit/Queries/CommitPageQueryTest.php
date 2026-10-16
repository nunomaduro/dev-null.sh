<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitPageQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('loads the commit with what its page shows the viewer', function (): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    $commit->stars()->attach([$viewer->id, User::factory()->create()->id]);
    Bookmark::factory()->create(['user_id' => $viewer->id, 'commit_id' => $commit->id]);

    $loaded = new CommitPageQuery()->get($commit, $viewer);

    expect($loaded->replies_count)->toBe(1)
        ->and($loaded->stars_count)->toBe(2)
        ->and($loaded->starred)->toBeTrue()
        ->and($loaded->is_saved)->toBeTrue()
        ->and($loaded->relationLoaded('user'))->toBeTrue()
        ->and($loaded->relationLoaded('linkPreview'))->toBeTrue()
        ->and($loaded->relationLoaded('images'))->toBeTrue();
});

it('only counts the stars and saves of the viewer as theirs', function (): void {
    $commit = Commit::factory()->create();
    $commit->stars()->attach(User::factory()->create());
    Bookmark::factory()->create(['commit_id' => $commit->id]);

    $loaded = new CommitPageQuery()->get($commit, User::factory()->create());

    expect($loaded->stars_count)->toBe(1)
        ->and($loaded->starred)->toBeFalse()
        ->and($loaded->is_saved)->toBeFalse();
});

it('loads the commit for a guest, who starred and saved nothing', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    $commit->stars()->attach(User::factory()->create());
    Bookmark::factory()->create(['commit_id' => $commit->id]);

    $loaded = new CommitPageQuery()->get($commit, null);

    expect($loaded->replies_count)->toBe(1)
        ->and($loaded->stars_count)->toBe(1)
        ->and($loaded->starred)->toBeFalse()
        ->and($loaded->is_saved)->toBeFalse();
});

it('does not find a deleted commit', function (): void {
    $commit = Commit::factory()->create();
    $commit->delete();

    new CommitPageQuery()->get($commit, User::factory()->create());
})->throws(ModelNotFoundException::class);
