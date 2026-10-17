<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\Log;
use App\Models\Commit;
use App\Models\User;

it('may star a commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    User::factory()->create()->starredCommits()->attach($commit);

    $response = $this->actingAs($user)
        ->postJson(route('commit-star.store', $commit));

    $response->assertOk()
        ->assertExactJson(['starred' => true, 'stars_count' => 2, 'author' => ['id' => $commit->user_id, 'stars_received' => 2]]);

    expect($user->hasStarred($commit))->toBeTrue();
});

it('returns the stars the author received across all their commits', function (): void {
    $author = User::factory()->create();
    $commit = Commit::factory()->for($author)->create();
    $reply = Commit::factory()->for($author)->for(Commit::factory(), 'parent')->create();
    $hidden = Commit::factory()->for($author)->pending()->create();

    User::factory()->create()->starredCommits()->attach([$reply->id, $hidden->id]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-star.store', $commit))
        ->assertOk()
        ->assertJsonPath('author.id', $author->id)
        ->assertJsonPath('author.stars_received', 2);
});

it('starring is idempotent', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $this->actingAs($user)->postJson(route('commit-star.store', $commit))->assertOk();
    $this->actingAs($user)->postJson(route('commit-star.store', $commit))
        ->assertOk()
        ->assertExactJson(['starred' => true, 'stars_count' => 1, 'author' => ['id' => $commit->user_id, 'stars_received' => 1]]);

    expect($user->starredCommits()->count())->toBeOne();
});

it('may not star their own commit', function (CommitStatus $status): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['status' => $status]);

    $this->actingAs($user)
        ->postJson(route('commit-star.store', $commit))
        ->assertForbidden();

    expect($user->hasStarred($commit))->toBeFalse();
})->with(CommitStatus::cases());

it('may not star a commit across a block in either direction', function (bool $userBlocks): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $userBlocks ? $user->blocking()->attach($commit->user) : $commit->user->blocking()->attach($user);

    $this->actingAs($user)
        ->post(route('commit-star.store', $commit))
        ->assertForbidden();

    expect($user->hasStarred($commit))->toBeFalse();
})->with([true, false]);

it('may not star a commit that is not visible', function (string $state): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->{$state}()->create();

    $this->actingAs($user)
        ->postJson(route('commit-star.store', $commit))
        ->assertForbidden();

    expect($user->hasStarred($commit))->toBeFalse();
})->with(['pending', 'rejected']);

it('may unstar a commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $user->starredCommits()->attach($commit);
    User::factory()->create()->starredCommits()->attach($commit);

    $response = $this->actingAs($user)
        ->deleteJson(route('commit-star.destroy', $commit));

    $response->assertOk()
        ->assertExactJson(['starred' => false, 'stars_count' => 1, 'author' => ['id' => $commit->user_id, 'stars_received' => 1]]);

    expect($user->hasStarred($commit))->toBeFalse();
});

it('requires authentication to star', function (): void {
    $commit = Commit::factory()->create();

    $this->postJson(route('commit-star.store', $commit))
        ->assertUnauthorized();

    expect($commit->stars()->count())->toBe(0);
});

it('requires authentication to unstar', function (): void {
    $commit = Commit::factory()->create();

    $this->deleteJson(route('commit-star.destroy', $commit))
        ->assertUnauthorized();
});

it('shows the star count and whether the viewer starred the commit', function (): void {
    $user = User::factory()->create();
    $starred = Commit::factory()->create(['body' => 'Starred', 'created_at' => now()->subMinute()]);
    $plain = Commit::factory()->create(['body' => 'Plain']);

    $user->starredCommits()->attach($starred);
    User::factory()->create()->starredCommits()->attach($starred);

    $response = $this->actingAs($user)->get(route('log.show', Log::Trending));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.body', 'Plain')
            ->where('commits.data.0.stars_count', 0)
            ->where('commits.data.0.starred', false)
            ->where('commits.data.1.body', 'Starred')
            ->where('commits.data.1.stars_count', 2)
            ->where('commits.data.1.starred', true));
});
