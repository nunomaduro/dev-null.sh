<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('lists visible replies oldest first with their own reply counts', function (): void {
    $parent = Commit::factory()->create();

    $second = Commit::factory()->for($parent, 'parent')->create(['body' => 'Second', 'created_at' => now()->subMinute()]);
    Commit::factory()->for($parent, 'parent')->create(['body' => 'First', 'created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($parent, 'parent')->for($this->user)->pending()->create(['body' => 'Mine, pending']);
    Commit::factory()->for($parent, 'parent')->pending()->create(['body' => 'Hidden']);
    Commit::factory()->for($second, 'parent')->count(2)->create();
    Commit::factory()->for($second, 'parent')->pending()->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.body', 'First')
        ->assertJsonPath('data.0.parent_id', $parent->id)
        ->assertJsonPath('data.1.body', 'Second')
        ->assertJsonPath('data.1.replies_count', 2)
        ->assertJsonPath('data.2.body', 'Mine, pending')
        ->assertJsonPath('meta.next_cursor', null);
});

it('shows the stars and saves of each reply as the user sees them', function (): void {
    $parent = Commit::factory()->create();
    $starred = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinutes(2)]);
    $starred->stars()->attach([$this->user->id, User::factory()->create()->id]);
    Bookmark::factory()->create(['user_id' => $this->user->id, 'commit_id' => $starred->id]);
    $starredByOthers = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinute()]);
    $starredByOthers->stars()->attach(User::factory()->create());
    Bookmark::factory()->create(['commit_id' => $starredByOthers->id]);
    Commit::factory()->for($parent, 'parent')->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonPath('data.0.stars_count', 2)
        ->assertJsonPath('data.0.starred', true)
        ->assertJsonPath('data.0.is_saved', true)
        ->assertJsonPath('data.1.stars_count', 1)
        ->assertJsonPath('data.1.starred', false)
        ->assertJsonPath('data.1.is_saved', false)
        ->assertJsonPath('data.2.stars_count', 0)
        ->assertJsonPath('data.2.starred', false)
        ->assertJsonPath('data.2.is_saved', false);
});

it('does not query each reply, its author or its stars', function (): void {
    $parent = Commit::factory()->create();

    $queries = function () use ($parent): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent))->assertOk();

        return count(DB::getQueryLog());
    };

    $create = function (int $count) use ($parent): void {
        foreach (Commit::factory()->for($parent, 'parent')->count($count)->create() as $reply) {
            $reply->stars()->attach([$this->user->id, User::factory()->create()->id]);
        }
    };

    // The first signed request also syncs the user's keys with GitHub...
    $queries();

    $create(2);
    $few = $queries();

    $create(8);

    expect($queries())->toBe($few);
});

it('lists replies 50 at a time with a cursor to the next page', function (): void {
    $parent = Commit::factory()->create();

    Commit::factory()->for($parent, 'parent')->count(52)
        ->state(new Sequence(fn (Sequence $sequence): array => [
            'body' => "Reply {$sequence->index}",
            'created_at' => now()->subMinutes(100 - $sequence->index),
        ]))
        ->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $first->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.49.body', 'Reply 49');

    $second = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', [$parent, 'cursor' => $first->json('meta.next_cursor')]));

    $second->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Reply 50');
});

it('hides the replies of commits the user may not see', function (): void {
    $parent = Commit::factory()->pending()->create();
    Commit::factory()->for($parent, 'parent')->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertNotFound();
});

it('lists deleted replies that still have replies as tombstones', function (): void {
    $parent = Commit::factory()->create();

    $tombstone = Commit::factory()->for($parent, 'parent')->create(['body' => 'Gone', 'created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($tombstone, 'parent')->create();
    $tombstone->delete();
    Commit::factory()->for($parent, 'parent')->create(['body' => 'Also gone', 'created_at' => now()->subMinute()])->delete();
    $pendingTombstone = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($pendingTombstone, 'parent')->pending()->create();
    $pendingTombstone->delete();
    $kept = Commit::factory()->for($parent, 'parent')->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', [
            'id' => $tombstone->id,
            'parent_id' => $parent->id,
            'deleted_at' => now()->toIso8601ZuluString(),
            'replies_count' => 1,
        ])
        ->assertJsonPath('data.1.id', $kept->id)
        ->assertDontSee('Gone');
});

it('lists the replies of a deleted commit', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $parent->delete();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id);
});

it('hides the replies of deleted commits the user may not see', function (): void {
    $parent = Commit::factory()->pending()->create();
    Commit::factory()->for($parent, 'parent')->create();
    $parent->delete();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent));

    $response->assertNotFound();
});

it('rejects requests that are not signed', function (): void {
    $this->getJson(route('api-commit-reply.index', Commit::factory()->create()))->assertUnauthorized();
});

it('leaves out replies by muted people and across blocks, as the web does', function (): void {
    $muted = User::factory()->create();
    $blocker = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->user->muting()->attach($muted);
    $blocker->blocking()->attach($this->user);

    $reply = Commit::factory()->for($parent, 'parent')->create();
    Commit::factory()->for($parent, 'parent')->for($muted)->create();
    Commit::factory()->for($parent, 'parent')->for($blocker)->create();

    signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$reply->id]);
});

it('returns not found for the replies to a commit across a block', function (): void {
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->create();

    $this->user->blocking()->attach($parent->user);

    signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $parent))->assertNotFound();
});

it('lists quotes of the commit with its replies, as the web does', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create(['created_at' => now()->subMinute()]);
    $quote = Commit::factory()->create(['quoted_commit_id' => $commit->id]);

    signedApiRequest($this->keyPair, 'GET', route('api-commit-reply.index', $commit))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$reply->id, $quote->id]);
});
