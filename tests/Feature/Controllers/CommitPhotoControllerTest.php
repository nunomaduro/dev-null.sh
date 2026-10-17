<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

function commitWithPhotos(int $count = 2): Commit
{
    $commit = Commit::factory()->create(['body' => 'Offsite photo dump']);

    CommitImage::factory()
        ->for($commit)
        ->count($count)
        ->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index])
        ->create();

    return $commit;
}

it('shows the commit page with the photo open', function (): void {
    $commit = commitWithPhotos();
    Commit::factory()->for($commit, 'parent')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit-photo.show', [$commit->user, $commit, 2]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('commit/show')
            ->where('commit.id', $commit->id)
            ->has('replies.data', 1)
            ->where('photo.index', 1)
            ->where('photo.commit.id', $commit->id)
            ->where('photo.commit.body', 'Offsite photo dump')
            ->has('photo.commit.images', 2)
            ->where('photo.commit.replies_count', 1));
});

it('reloads the photo commit alone without loading its thread', function (): void {
    $viewer = User::factory()->create();
    $queries = function (Commit $commit) use ($viewer): int {
        $this->actingAs($viewer)
            ->get(route('commit-photo.show', [$commit->user, $commit, 1]))
            ->assertOk()
            ->assertInertia(function ($page) use ($commit): void {
                DB::flushQueryLog();
                DB::enableQueryLog();

                $page->reloadOnly('commit', fn ($reload) => $reload
                    ->where('commit.id', $commit->id)
                    ->missing('replies')
                    ->missing('ancestors')
                    ->missing('photo'));

                DB::disableQueryLog();
            });

        return count(DB::getQueryLog());
    };
    $alone = commitWithPhotos();
    $inThread = commitWithPhotos();
    $inThread->parent()->associate(Commit::factory()->for(Commit::factory(), 'parent')->create())->save();
    Commit::factory()->for($inThread, 'parent')->count(3)->create();

    expect($queries($inThread))->toBe($queries($alone));
});

it('does not show a photo the commit does not have', function (int $photo): void {
    $commit = commitWithPhotos();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit-photo.show', [$commit->user, $commit, $photo]));

    $response->assertNotFound();
})->with([0, 3]);

it('does not show a photo of a commit that is not visible', function (): void {
    $commit = commitWithPhotos();
    $commit->update(['status' => 'pending']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit-photo.show', [$commit->user, $commit, 1]));

    $response->assertNotFound();
});

it('does not show a photo of a deleted commit', function (): void {
    $commit = commitWithPhotos();
    $commit->delete();

    $response = $this->actingAs($commit->user)
        ->get(route('commit-photo.show', [$commit->user, $commit, 1]));

    $response->assertNotFound();
});

it('does not show a photo of a commit under another username', function (): void {
    $commit = commitWithPhotos();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit-photo.show', [User::factory()->create(), $commit, 1]));

    $response->assertNotFound();
});

it('sends guests to the commit', function (): void {
    $commit = commitWithPhotos();

    $response = $this->get(route('commit-photo.show', [$commit->user, $commit, 1]));

    $response->assertRedirectToRoute('commit.show', [$commit->user, $commit]);
});

it('does not show photos across a block in either direction', function (bool $viewerBlocks): void {
    $viewer = User::factory()->create();
    $commit = commitWithPhotos();

    $viewerBlocks ? $viewer->blocking()->attach($commit->user) : $commit->user->blocking()->attach($viewer);

    $this->actingAs($viewer)
        ->get(route('commit-photo.show', [$commit->user, $commit, 1]))
        ->assertNotFound();
})->with([true, false]);

it('loads a photo commit quote without exposing moderation hints', function (): void {
    $quoted = Commit::factory()->create(['body' => 'Quoted photo context']);
    $commit = commitWithPhotos();
    $commit->update(['quoted_commit_id' => $quoted->id, 'ai_decision' => CommitStatus::Rejected, 'ai_categories' => ['violence']]);

    $this->actingAs(User::factory()->create())
        ->get(route('commit-photo.show', [$commit->user, $commit, 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.body', 'Quoted photo context')
            ->where('photo.commit.quoted_commit.id', $quoted->id)
            ->missing('commit.ai_decision')
            ->missing('photo.commit.ai_categories'));
});

it('hides quoted photo context across a block in either direction', function (bool $viewerBlocks): void {
    $viewer = User::factory()->create();
    $quoted = Commit::factory()->create(['body' => 'Blocked quote context']);
    $commit = commitWithPhotos();
    $commit->update(['quoted_commit_id' => $quoted->id]);

    $viewerBlocks ? $viewer->blocking()->attach($quoted->user) : $quoted->user->blocking()->attach($viewer);

    $this->actingAs($viewer)
        ->get(route('commit-photo.show', [$commit->user, $commit, 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit', null)
            ->where('photo.commit.quoted_commit', null))
        ->assertDontSee('Blocked quote context');
})->with([true, false]);
