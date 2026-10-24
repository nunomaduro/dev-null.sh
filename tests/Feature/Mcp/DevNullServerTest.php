<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Mcp\Servers\DevNullServer;
use App\Mcp\Tools\CreateCommitStarTool;
use App\Mcp\Tools\CreateCommitTool;
use App\Mcp\Tools\CreateUserFollowTool;
use App\Mcp\Tools\ListUsersTool;
use App\Mcp\Tools\ShowCommitTool;
use App\Mcp\Tools\ShowLogTool;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('shows the commits of the people the user follows', function (): void {
    $user = User::factory()->create();
    $followed = User::factory()->create();
    $user->following()->attach($followed);

    Commit::factory()->for($followed)->create(['body' => 'From someone followed']);
    Commit::factory()->create(['body' => 'From a stranger']);

    DevNullServer::actingAs($user)
        ->tool(ShowLogTool::class)
        ->assertOk()
        ->assertSee('From someone followed')
        ->assertDontSee('From a stranger');
});

it('shows the latest commits of everyone', function (): void {
    $user = User::factory()->create();

    Commit::factory()->create(['body' => 'From a stranger']);
    Commit::factory()->pending()->create(['body' => 'Still pending']);

    DevNullServer::actingAs($user)
        ->tool(ShowLogTool::class, ['log' => 'latest'])
        ->assertOk()
        ->assertSee('From a stranger')
        ->assertDontSee('Still pending');
});

it('pages through the log with a cursor', function (): void {
    $user = User::factory()->create();

    Commit::factory()->count(21)->create();

    DevNullServer::actingAs($user)
        ->tool(ShowLogTool::class, ['log' => 'latest'])
        ->assertStructuredContent(fn ($json) => $json
            ->count('commits', 20)
            ->whereType('next_cursor', 'string')
            ->etc());
});

it('shows a commit by its hash with its thread', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create(['body' => 'The parent']);
    $commit = Commit::factory()->for($parent, 'parent')->create(['body' => 'The commit']);
    Commit::factory()->for($commit, 'parent')->create(['body' => 'A reply']);

    DevNullServer::actingAs($user)
        ->tool(ShowCommitTool::class, ['commit' => mb_substr($commit->id, -7)])
        ->assertOk()
        ->assertSee(['The commit', 'The parent', 'A reply']);
});

it('shows a deleted commit in the thread as deleted', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create(['body' => 'The deleted parent']);
    $commit = Commit::factory()->for($parent, 'parent')->create();
    $parent->delete();

    DevNullServer::actingAs($user)
        ->tool(ShowCommitTool::class, ['commit' => $commit->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ancestors.0.id', $parent->id)
            ->where('ancestors.0.deleted', true)
            ->etc())
        ->assertDontSee('The deleted parent');
});

it('asks for the full ID when a hash matches more than one commit', function (): void {
    $user = User::factory()->create();
    Commit::factory()->create(['id' => '01970000-0000-7000-8000-000011234567']);
    Commit::factory()->create(['id' => '01970000-0000-7000-8000-000021234567']);

    DevNullServer::actingAs($user)
        ->tool(ShowCommitTool::class, ['commit' => '1234567'])
        ->assertHasErrors(['That hash matches more than one commit: use the full ID instead.']);
});

it('cannot show a pending commit of someone else', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->pending()->create();

    DevNullServer::actingAs($user)
        ->tool(ShowCommitTool::class, ['commit' => $commit->id])
        ->assertHasErrors(['No commit found for that reference.']);
});

it('rejects references that are no commit', function (): void {
    $user = User::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(ShowCommitTool::class, ['commit' => 'not a commit'])
        ->assertHasErrors(['That is not a commit hash, ID, or link.']);
});

it('finds users', function (): void {
    $user = User::factory()->create();
    User::factory()->create(['username' => 'taylorotwell']);

    DevNullServer::actingAs($user)
        ->tool(ListUsersTool::class, ['query' => '@taylor'])
        ->assertOk()
        ->assertSee('taylorotwell');
});

it('commits as the user', function (): void {
    $user = User::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => 'Hello from an agent'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('pending_review', true)
            ->where('commit.body', 'Hello from an agent')
            ->etc());

    $commit = $user->commits()->sole();

    expect($commit->status)->toBe(CommitStatus::Pending);
});

it('replies to a visible commit', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => 'A reply', 'parent_id' => $parent->id])
        ->assertOk();

    expect($user->commits()->sole()->parent_id)->toBe($parent->id);
});

it('cannot reply to a pending commit of someone else', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->pending()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => 'A reply', 'parent_id' => $parent->id])
        ->assertHasErrors(['That commit is no longer available to reply to.']);

    expect($user->commits()->exists())->toBeFalse();
});

it('commits a long body', function (): void {
    $user = User::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => str_repeat('a', Commit::MAX_BODY_LENGTH)])
        ->assertOk();

    expect($user->commits()->sole()->body)->toHaveLength(Commit::MAX_BODY_LENGTH);
});

it('validates the body of a commit', function (): void {
    $user = User::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => str_repeat('a', Commit::MAX_BODY_LENGTH + 1)])
        ->assertHasErrors();

    expect($user->commits()->exists())->toBeFalse();
});

it('commits at most 10 times a minute', function (): void {
    $user = User::factory()->create();

    RateLimiter::increment("mcp-commits:{$user->id}", amount: 10);

    DevNullServer::actingAs($user)
        ->tool(CreateCommitTool::class, ['body' => 'One too many'])
        ->assertHasErrors(['Slow down: 10 commits a minute. Even CI takes breaks.']);

    expect($user->commits()->exists())->toBeFalse();
});

it('stars a commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitStarTool::class, ['commit' => $commit->id])
        ->assertOk();

    expect($user->hasStarred($commit))->toBeTrue();
});

it('cannot star a pending commit of someone else', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->pending()->create();

    DevNullServer::actingAs($user)
        ->tool(CreateCommitStarTool::class, ['commit' => $commit->id])
        ->assertHasErrors();

    expect($user->hasStarred($commit))->toBeFalse();
});

it('follows a user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'someone']);

    DevNullServer::actingAs($user)
        ->tool(CreateUserFollowTool::class, ['username' => '@someone'])
        ->assertOk();

    expect($user->following()->whereKey($other->id)->exists())->toBeTrue();
});

it('cannot follow themselves or unknown users', function (): void {
    $user = User::factory()->create(['username' => 'me']);

    DevNullServer::actingAs($user)
        ->tool(CreateUserFollowTool::class, ['username' => 'me'])
        ->assertHasErrors(['You cannot follow yourself.']);

    DevNullServer::actingAs($user)
        ->tool(CreateUserFollowTool::class, ['username' => 'nobody'])
        ->assertHasErrors(['No user found with that username.']);

    expect($user->following()->exists())->toBeFalse();
});
