<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;

it('suggests the people the user follows without a term, most recently followed first', function (): void {
    $user = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    User::factory()->create();

    $user->following()->attach($first, ['created_at' => now()->subMinute()]);
    $user->following()->attach($second, ['created_at' => now()]);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=');

    $response->assertOk()
        ->assertJsonCount(2, 'users')
        ->assertJsonPath('users.0.id', $second->id)
        ->assertJsonPath('users.1.id', $first->id)
        ->assertJsonMissingPath('users.0.pivot')
        ->assertJsonMissingPath('users.0.email');
});

it('suggests matches, people the user follows first', function (): void {
    $user = User::factory()->create(['username' => 'viewer', 'name' => 'Viewer']);
    User::factory()->create(['username' => 'taylor', 'name' => 'Taylor']);
    User::factory()->create(['username' => 'taylorbot', 'name' => 'Bot']);
    $followed = User::factory()->create(['username' => 'taylorotwell', 'name' => 'Taylor Otwell']);
    User::factory()->create(['username' => 'jess', 'name' => 'Jess Taylor']);

    $user->following()->attach($followed);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=@tay');

    $response->assertOk()
        ->assertJsonCount(4, 'users')
        ->assertJsonPath('users.0.username', 'taylorotwell')
        ->assertJsonPath('users.1.username', 'taylor')
        ->assertJsonPath('users.2.username', 'taylorbot')
        ->assertJsonPath('users.3.username', 'jess');
});

it('matches literal wildcard characters in display names', function (string $name, string $query): void {
    $match = User::factory()->create(['username' => 'matching', 'name' => $name]);
    User::factory()->create(['username' => 'other', 'name' => 'JaneXDoe']);

    $this->actingAs(User::factory()->create(['username' => 'viewer']))
        ->getJson('/search/mentions?q='.rawurlencode($query))
        ->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.id', $match->id);
})->with([
    'underscore' => ['Jane_Doe', 'Jane_Doe'],
    'percent' => ['Discount 50%', '50%'],
    'escape character' => ['Bang!', 'Bang!'],
]);

it('never suggests the user themselves', function (): void {
    $user = User::factory()->create(['username' => 'nuno', 'name' => 'Nuno Maduro']);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=nuno');

    $response->assertOk()
        ->assertJsonCount(0, 'users');
});

it('never suggests muted people or people across a block', function (string $term): void {
    $user = User::factory()->create(['username' => 'viewer', 'name' => 'Viewer']);
    $muted = User::factory()->create(['username' => 'taylor_muted']);
    $blocked = User::factory()->create(['username' => 'taylor_blocked']);
    $blocker = User::factory()->create(['username' => 'taylor_blocker']);
    $visible = User::factory()->create(['username' => 'taylor']);

    $user->following()->attach([$muted->id, $visible->id]);
    $user->muting()->attach($muted);
    $user->blocking()->attach($blocked);
    $blocker->blocking()->attach($user);

    $response = $this->actingAs($user)->getJson('/search/mentions?q='.$term);

    $response->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.id', $visible->id);
})->with(['', 'taylor']);

it('limits the number of suggestions', function (): void {
    User::factory()->count(10)->sequence(fn ($sequence): array => ['username' => "dev{$sequence->index}"])->create();

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/search/mentions?q=dev');

    $response->assertOk()
        ->assertJsonCount(6, 'users');
});

it("only suggests an org's members within it, people the user follows first", function (): void {
    $mark = User::factory()->create(['username' => 'mark']);
    [$taylor, $jess] = followersOf($mark, 2);
    $taylor->update(['username' => 'taylor']);
    $jess->update(['username' => 'jess']);
    User::factory()->create(['username' => 'taylorotwell']);
    $org = createOrg($mark, [$taylor, $jess]);
    $mark->following()->attach($taylor);

    $this->actingAs($mark)
        ->getJson(route('mention-suggestion.index', ['q' => '', 'org' => $org]))
        ->assertOk()
        ->assertJsonPath('users.*.username', ['taylor', 'jess']);

    $this->getJson(route('mention-suggestion.index', ['q' => 'tay', 'org' => $org]))
        ->assertOk()
        ->assertJsonPath('users.*.username', ['taylor']);
});

it("does not suggest an org's members to people outside it", function (): void {
    $mark = User::factory()->create();
    [$taylor] = followersOf($mark, 1);
    $org = createOrg($mark, [$taylor]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('mention-suggestion.index', ['q' => '', 'org' => $org]))
        ->assertNotFound();
});

it('rejects overly long queries', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/search/mentions?q='.str_repeat('a', 101));

    $response->assertUnprocessable();
});

it('requires authentication', function (): void {
    $response = $this->getJson('/search/mentions?q=nuno');

    $response->assertUnauthorized();
});

it('ranks the direct recipient, root author, and nested contributors before ordinary matches', function (string $term): void {
    $viewer = User::factory()->create();
    $rootAuthor = User::factory()->create(['username' => 'zroot', 'name' => 'Match Root']);
    $recipient = User::factory()->create(['username' => 'zrecipient', 'name' => 'Match Recipient']);
    $contributor = User::factory()->create(['username' => 'zcontributor', 'name' => 'Match Contributor']);
    $outsider = User::factory()->create(['username' => 'match', 'name' => 'Outsider']);
    $viewer->following()->attach($outsider);
    $root = Commit::factory()->for($rootAuthor)->create();
    $reply = Commit::factory()->for($recipient)->create(['parent_id' => $root->id]);
    Commit::factory()->for($contributor)->create(['parent_id' => $reply->id]);
    Commit::factory()->for($contributor)->create(['parent_id' => $root->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['q' => $term, 'reply' => $reply->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$recipient->id, $rootAuthor->id, $contributor->id, $outsider->id]);
})->with(['', 'match']);

it('ranks a root recipient once before contributors', function (): void {
    $viewer = User::factory()->create();
    $author = User::factory()->create(['username' => 'zroot']);
    $contributor = User::factory()->create(['username' => 'acontributor']);
    $root = Commit::factory()->for($author)->create();
    Commit::factory()->for($contributor)->create(['parent_id' => $root->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => $root->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$author->id, $contributor->id]);
});

it('uses ordinary matching when none of the thread participants match', function (): void {
    $viewer = User::factory()->create(['username' => 'viewer', 'name' => 'Viewer']);
    $root = Commit::factory()->for(User::factory()->create(['username' => 'abc', 'name' => 'ABC']))->create();
    $match = User::factory()->create(['username' => 'ijk', 'name' => 'IJK']);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['q' => 'i', 'reply' => $root->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$match->id]);
});

it('prioritizes only the quoted author without looking up their thread contributors', function (string $term): void {
    $viewer = User::factory()->create();
    $author = User::factory()->create(['username' => 'zauthor', 'name' => 'Match Author']);
    $contributor = User::factory()->create(['username' => 'acontributor', 'name' => 'Match Contributor']);
    $followed = User::factory()->create(['username' => 'match', 'name' => 'Followed']);
    $viewer->following()->attach($followed);
    $root = Commit::factory()->for($author)->create();
    Commit::factory()->for($contributor)->create(['parent_id' => $root->id]);

    $response = $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['q' => $term, 'quote' => $root->id]))
        ->assertOk();

    $response->assertJsonPath('users.*.id', $term === ''
        ? [$author->id, $followed->id]
        : [$author->id, $followed->id, $contributor->id]);
})->with(['', 'match']);

it('does not promote authors of hidden replies or replies in other threads', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->for($viewer)->create();
    $hidden = User::factory()->count(4)->create();
    Commit::factory()->for($hidden[0])->pending()->create(['parent_id' => $root->id]);
    Commit::factory()->for($hidden[1])->rejected()->create(['parent_id' => $root->id]);
    Commit::factory()->for($hidden[2])->create(['parent_id' => $root->id, 'deleted_at' => now()]);
    Commit::factory()->for($hidden[3])->create(['parent_id' => Commit::factory()->create()->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => $root->id]))
        ->assertOk()
        ->assertJsonCount(0, 'users');
});

it('keeps self, muted, and blocked participants out of contextual suggestions', function (string $context): void {
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $blocked = User::factory()->create();
    $blocker = User::factory()->create();
    $root = Commit::factory()->for($viewer)->create();
    foreach ([$muted, $blocked, $blocker] as $participant) {
        Commit::factory()->for($participant)->create(['parent_id' => $root->id]);
    }

    $viewer->muting()->attach($muted);
    $viewer->blocking()->attach($blocked);
    $blocker->blocking()->attach($viewer);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', [$context => $root->id]))
        ->assertOk()
        ->assertJsonCount(0, 'users');
})->with(['reply', 'quote']);

it('does not prioritize the author of an unavailable root over visible contributors', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->create(['deleted_at' => now()]);
    $reply = Commit::factory()->create(['parent_id' => $root->id]);
    $contributor = User::factory()->create();
    Commit::factory()->for($contributor)->create(['parent_id' => $reply->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => $reply->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$reply->user_id, $contributor->id]);
});

it('rejects inaccessible commit context with the generic unavailable message', function (string $context): void {
    $viewer = User::factory()->create();
    $hidden = Commit::factory()->pending()->create();

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', [$context => $hidden->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$context => 'That commit is no longer available to reply to.']);
})->with(['reply', 'quote']);

it('rejects malformed commit context', function (string $context): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('mention-suggestion.index', [$context => 'not-a-uuid']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($context);
})->with(['reply', 'quote']);

it('rejects mixing reply and quote contexts', function (): void {
    $root = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('mention-suggestion.index', ['reply' => $root->id, 'quote' => $root->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reply');
});

it('rejects mixing an org and commit context', function (string $context): void {
    $viewer = User::factory()->create();
    $org = createOrg($viewer, followersOf($viewer, 1));
    $root = Commit::factory()->create();

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['org' => $org, $context => $root->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('org');
})->with(['reply', 'quote']);

it('keeps ordinary bare-at suggestions in recently followed order after the recipient', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->create();
    $older = User::factory()->create(['username' => 'aaa']);
    $newer = User::factory()->create(['username' => 'zzz']);
    $viewer->following()->attach($older, ['created_at' => now()->subMinute()]);
    $viewer->following()->attach($newer, ['created_at' => now()]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => $root->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$root->user_id, $newer->id, $older->id]);
});

it('applies thread priority before limiting the matching suggestions', function (): void {
    $viewer = User::factory()->create();
    $author = User::factory()->create(['username' => 'zdev', 'name' => 'Author']);
    $root = Commit::factory()->for($author)->create();
    User::factory()->count(8)->sequence(fn ($sequence): array => ['username' => "adev{$sequence->index}"])->create();

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['q' => 'dev', 'reply' => $root->id]))
        ->assertOk()
        ->assertJsonCount(6, 'users')
        ->assertJsonPath('users.0.id', $author->id);
});

it('prioritizes the quoted reply author rather than the root author', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->create();
    $reply = Commit::factory()->create(['parent_id' => $root->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['quote' => $reply->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$reply->user_id]);
});

it('uses the quote thread rather than the quoted thread when replying to a quote', function (): void {
    $viewer = User::factory()->create();
    $original = Commit::factory()->create();
    Commit::factory()->create(['parent_id' => $original->id]);
    $quote = Commit::factory()->create(['quoted_commit_id' => $original->id]);
    $reply = Commit::factory()->create(['parent_id' => $quote->id]);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => $quote->id]))
        ->assertOk()
        ->assertJsonPath('users.*.id', [$quote->user_id, $reply->user_id]);
});

it('handles non-uuid org, reply, and quote parameters gracefully without database exceptions', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['org' => 'not-a-valid-uuid']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['org']);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['reply' => 'not-a-valid-uuid']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reply']);

    $this->actingAs($viewer)
        ->getJson(route('mention-suggestion.index', ['quote' => 'not-a-valid-uuid']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quote']);
});
