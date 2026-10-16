<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Jobs\ModerateCommit;
use App\Models\Bookmark;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('shows an approved commit to anyone', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
    ]);
    $commit = Commit::factory()->for($user)->create([
        'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
        'body' => 'Shipped the public API',
    ]);

    $response = $this->getJson(route('api-commit.show', $commit));

    $response->assertOk()
        ->assertExactJson(['data' => [
            'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
            'short_hash' => 'e3f4a5b',
            'body' => 'Shipped the public API',
            'parent_id' => null,
            'html_url' => route('commit.show', [$user, $commit]),
            'created_at' => now()->toIso8601ZuluString(),
            'stars_count' => 0,
            'replies_count' => 0,
            'link_preview' => null,
            'images' => [],
            'author' => [
                'id' => $user->id,
                'username' => 'octocat',
                'name' => 'The Octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
                'bio' => $user->bio,
                'company' => $user->company,
                'location' => $user->location,
                'website' => $user->website,
                'github_url' => 'https://github.com/octocat',
                'html_url' => route('user.show', $user),
                'feed_url' => route('user-feed.show', $user),
                'created_at' => now()->toIso8601ZuluString(),
            ],
        ]]);
});

it('counts only approved replies and every star', function (): void {
    $commit = Commit::factory()->create();

    Commit::factory()->for($commit, 'parent')->count(2)->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    Commit::factory()->for($commit, 'parent')->rejected()->create();
    Commit::factory()->for($commit, 'parent')->create()->delete();

    $commit->stars()->attach(User::factory()->count(3)->create());

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.replies_count', 2)
        ->assertJsonPath('data.stars_count', 3);
});

it('shows the parent of a reply only while the parent is public', function (Closure $hide, bool $isPublic): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $hide($parent);

    $this->getJson(route('api-commit.show', $reply))
        ->assertOk()
        ->assertJsonPath('data.parent_id', $isPublic ? $parent->id : null);
})->with([
    'approved' => [fn (Commit $parent): null => null, true],
    'pending' => [fn (Commit $parent): bool => $parent->forceFill(['status' => CommitStatus::Pending])->save(), false],
    'rejected' => [fn (Commit $parent): bool => $parent->forceFill(['status' => CommitStatus::Rejected])->save(), false],
    'deleted' => [fn (Commit $parent): ?bool => $parent->delete(), false],
]);

it('includes the link preview without internal fields', function (): void {
    $preview = LinkPreview::factory()->fetched()->create([
        'url' => 'https://github.com/nunomaduro',
        'title' => 'Nuno Maduro',
    ]);
    $commit = Commit::factory()->create(['link_preview_id' => $preview->id]);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.link_preview', [
            'url' => 'https://github.com/nunomaduro',
            'title' => 'Nuno Maduro',
            'description' => $preview->description,
            'image_url' => $preview->image_url,
            'site_name' => $preview->site_name,
            'embed' => null,
        ]);
});

it('includes the embed of the link preview', function (): void {
    $preview = LinkPreview::factory()->fetched()->create(['url' => 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT']);
    $commit = Commit::factory()->create(['link_preview_id' => $preview->id]);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.link_preview.embed', [
            'provider' => 'Spotify',
            'kind' => 'audio',
            'src' => 'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
            'aspect_ratio' => null,
            'height' => 152,
        ]);
});

it('includes the images of the commit in order', function (): void {
    $commit = Commit::factory()->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1, 'width' => 640, 'height' => 480]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0, 'description' => 'A terminal showing a successful build.']);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertDontSee($first->path)
        ->assertJsonPath('data.images', [
            ['id' => $first->id, 'width' => 1200, 'height' => 800, 'description' => 'A terminal showing a successful build.', 'url' => route('commit-image.show', $first)],
            ['id' => $second->id, 'width' => 640, 'height' => 480, 'description' => null, 'url' => route('commit-image.show', $second)],
        ]);
});

it('never exposes hidden user fields', function (): void {
    $user = User::factory()->create(['email' => 'octocat@github.com', 'preferred_lane' => 3]);
    $commit = Commit::factory()->for($user)->create();

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertDontSee('octocat@github.com')
        ->assertJsonMissingPath('data.author.email')
        ->assertJsonMissingPath('data.author.github_id')
        ->assertJsonMissingPath('data.author.preferred_lane')
        ->assertJsonMissingPath('data.author.remember_token');
});

it('returns not found for commits that are not public', function (Closure $factory): void {
    $commit = $factory();

    $this->getJson("/api/v1/commits/{$commit->id}")
        ->assertNotFound()
        ->assertJsonMissingPath('data');
})->with([
    'pending' => [fn (): Commit => Commit::factory()->pending()->create(['body' => 'Waiting for review'])],
    'rejected' => [fn (): Commit => Commit::factory()->rejected()->create(['body' => 'Waiting for review'])],
    'deleted' => [fn (): Commit => tap(Commit::factory()->create(['body' => 'Waiting for review']))->delete()],
]);

it('returns not found for unknown and malformed ids', function (string $id): void {
    $this->getJson("/api/v1/commits/{$id}")->assertNotFound();
})->with([
    'unknown' => ['01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3'],
    'malformed' => ['not-a-uuid'],
]);

it('answers in json without being asked to', function (): void {
    $this->get('/api/v1/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('allows browsers on any origin to read it', function (): void {
    $commit = Commit::factory()->create();

    $this->getJson(route('api-commit.show', $commit), ['Origin' => 'https://example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('limits each address to 60 requests a minute', function (): void {
    $commit = Commit::factory()->create();

    foreach (range(1, 60) as $attempt) {
        $this->getJson(route('api-commit.show', $commit))->assertOk();
    }

    $this->getJson(route('api-commit.show', $commit))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60);

    $this->getJson(route('api-commit.show', $commit), ['REMOTE_ADDR' => '10.0.0.2'])->assertOk();
});

it('shows a signed request the commit as its page shows it to its user', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $keyPair = apiKeyPairFor($user);
    $root = Commit::factory()->create(['body' => 'Who broke prod?', 'created_at' => now()->subMinute()]);
    $commit = Commit::factory()->for($user)->for($root, 'parent')->create([
        'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
        'body' => 'Ask @nunomaduro about `@Override`',
    ]);
    $image = CommitImage::factory()->for($commit)->create();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    $commit->stars()->attach($user);
    Bookmark::factory()->create(['user_id' => $user->id, 'commit_id' => $commit->id]);

    $response = signedApiRequest($keyPair, 'GET', route('api-commit.show', $commit));

    $author = fn (User $author): array => [
        'id' => $author->id,
        'username' => $author->username,
        'name' => $author->name,
        'avatar_url' => $author->avatar_url,
        'bio' => $author->bio,
        'company' => $author->company,
        'location' => $author->location,
        'website' => $author->website,
        'github_url' => "https://github.com/{$author->username}",
        'html_url' => route('user.show', $author),
        'feed_url' => route('user-feed.show', $author),
        'created_at' => now()->toIso8601ZuluString(),
        'lane' => $author->lane(),
    ];

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
                'short_hash' => 'e3f4a5b',
                'body' => 'Ask @nunomaduro about `@Override`',
                'parent_id' => $root->id,
                'html_url' => route('commit.show', [$user, $commit]),
                'created_at' => now()->toIso8601ZuluString(),
                'stars_count' => 1,
                'replies_count' => 1,
                'link_preview' => null,
                'images' => [
                    ['id' => $image->id, 'width' => 1200, 'height' => 800, 'description' => null, 'url' => route('commit-image.show', $image)],
                ],
                'author' => $author($user),
                'body_segments' => [
                    ['type' => 'text', 'text' => 'Ask @nunomaduro about '],
                    ['type' => 'code', 'code' => '@Override'],
                ],
                'mentions' => [['username' => 'nunomaduro', 'offset' => 4, 'length' => 11]],
                'status' => 'approved',
                'is_pinned' => false,
                'starred' => true,
                'is_saved' => true,
                'deleted_at' => null,
            ],
            'ancestors' => [[
                'id' => $root->id,
                'short_hash' => mb_substr(str_replace('-', '', $root->id), -7),
                'body' => 'Who broke prod?',
                'parent_id' => null,
                'html_url' => route('commit.show', [$root->user, $root]),
                'created_at' => now()->subMinute()->toIso8601ZuluString(),
                'stars_count' => 0,
                'link_preview' => null,
                'images' => [],
                'author' => $author($root->user),
                'body_segments' => [['type' => 'text', 'text' => 'Who broke prod?']],
                'mentions' => [],
                'status' => 'approved',
                'is_pinned' => false,
                'starred' => false,
                'is_saved' => false,
                'deleted_at' => null,
            ]],
        ]);
});

it('shows a signed-in user their own pending commits, and the parents they may see', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $parent = Commit::factory()->for($user)->pending()->create();
    $commit = Commit::factory()->for($user)->for($parent, 'parent')->pending()->create();
    $image = CommitImage::factory()->for($commit)->create();

    $this->getJson(route('api-commit.show', $commit))->assertNotFound();

    signedApiRequest($keyPair, 'GET', route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.parent_id', $parent->id)
        ->assertJsonPath('data.images.0.id', $image->id)
        ->assertJsonPath('ancestors.0.id', $parent->id)
        ->assertJsonPath('ancestors.0.status', 'pending');
});

it('hides from a signed request the commits its user may not see', function (Closure $factory): void {
    $keyPair = apiKeyPairFor(User::factory()->create());

    signedApiRequest($keyPair, 'GET', route('api-commit.show', $factory()))->assertNotFound();
})->with([
    'pending' => [fn (): Commit => Commit::factory()->pending()->create()],
    'rejected' => [fn (): Commit => Commit::factory()->rejected()->create()],
    'deleted' => [fn (): Commit => tap(Commit::factory()->create())->delete()],
]);

it('hides from a signed request the commits and ancestors across a block, as the web does', function (): void {
    $viewer = User::factory()->create();
    $keyPair = apiKeyPairFor($viewer);
    $blocker = User::factory()->create();
    $blocked = Commit::factory()->for($blocker)->create();
    $reply = Commit::factory()->for($blocked, 'parent')->create();

    $blocker->blocking()->attach($viewer);

    signedApiRequest($keyPair, 'GET', route('api-commit.show', $blocked))->assertNotFound();
    signedApiRequest($keyPair, 'GET', route('api-commit.show', $reply))
        ->assertOk()
        ->assertJsonPath('data.parent_id', $blocked->id)
        ->assertJsonCount(0, 'ancestors');
    $this->getJson(route('api-commit.show', $blocked))->assertOk();
});

it('hides from a signed request the ancestors its user may not see', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $hidden = Commit::factory()->for(Commit::factory(), 'parent')->pending()->create();
    $commit = Commit::factory()->for($user)->for($hidden, 'parent')->create();

    signedApiRequest($keyPair, 'GET', route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.parent_id', $hidden->id)
        ->assertJsonCount(0, 'ancestors');
});

it('shows deleted ancestors to a signed request as tombstones', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $root = Commit::factory()->create(['created_at' => now()->subMinutes(2)]);
    $deleted = Commit::factory()->for($root, 'parent')->create(['body' => 'Gone', 'created_at' => now()->subMinute()]);
    $commit = Commit::factory()->for($deleted, 'parent')->create();
    $deleted->delete();

    signedApiRequest($keyPair, 'GET', route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonCount(2, 'ancestors')
        ->assertJsonPath('ancestors.0.id', $root->id)
        ->assertJsonPath('ancestors.1', [
            'id' => $deleted->id,
            'parent_id' => $root->id,
            'deleted_at' => now()->toIso8601ZuluString(),
        ])
        ->assertDontSee('Gone');
});

it('links the topmost ancestor to its parent when a thread is too deep to show it all', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $parent = Commit::factory()->create();

    foreach (range(1, 21) as $depth) {
        $parent = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinutes(30 - $depth)]);
    }

    $response = signedApiRequest($keyPair, 'GET', route('api-commit.show', $parent));

    $response->assertOk()
        ->assertJsonCount(20, 'ancestors')
        ->assertJsonPath('ancestors.0.parent.id', $response->json('ancestors.0.parent_id'))
        ->assertJsonMissingPath('ancestors.1.parent');
});

it('creates a commit from a signed request, pending review', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $keyPair = apiKeyPairFor($user);

    $response = signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => '  It was DNS :tada:  ']);

    $commit = $user->commits()->sole();

    $response->assertCreated()
        ->assertJsonPath('data.id', $commit->id)
        ->assertJsonPath('data.body', 'It was DNS 🎉')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.replies_count', 0)
        ->assertJsonPath('data.stars_count', 0)
        ->assertJsonPath('data.starred', false)
        ->assertJsonPath('data.is_saved', false)
        ->assertJsonPath('data.images', [])
        ->assertJsonPath('data.author.id', $user->id)
        ->assertJsonMissingPath('ancestors');

    expect($commit->status)->toBe(CommitStatus::Pending);
});

it('publishes the commits of admins right away', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    config(['app.admins' => [$user->username]]);

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => 'It was DNS.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved');
});

it('replies to a commit from a signed request', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $parent = Commit::factory()->create();

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => 'It was DNS.', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $parent->id);

    expect($user->commits()->sole()->parent_id)->toBe($parent->id);
});

it('validates a commit from a signed request', function (Closure $data, string $field, string $message): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), $data())
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect($user->commits()->exists())->toBeFalse();
})->with([
    'missing body' => [fn (): array => [], 'body', 'The body field is required.'],
    'too short' => [fn (): array => ['body' => '  ab  '], 'body', 'The body field must be at least 3 characters.'],
    'too long' => [fn (): array => ['body' => str_repeat('a', Commit::MAX_BODY_LENGTH + 1)], 'body', 'The body field must not be greater than '.Commit::MAX_BODY_LENGTH.' characters.'],
    'parent that is not a uuid' => [fn (): array => ['body' => 'It was DNS.', 'parent_id' => 'abc'], 'parent_id', 'The parent id field must be a valid UUID.'],
    'unknown parent' => [fn (): array => ['body' => 'It was DNS.', 'parent_id' => '0199e3a4-0000-7000-8000-000000000000'], 'parent_id', 'That commit is no longer available to reply to.'],
    'hidden parent' => [fn (): array => ['body' => 'It was DNS.', 'parent_id' => Commit::factory()->pending()->create()->id], 'parent_id', 'That commit is no longer available to reply to.'],
    'deleted parent' => [fn (): array => ['body' => 'It was DNS.', 'parent_id' => tap(Commit::factory()->create())->delete()->id], 'parent_id', 'That commit is no longer available to reply to.'],
]);

it('may not reply to a commit across a block, as on the web', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $parent = Commit::factory()->create();

    $parent->user->blocking()->attach($user);

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => 'It was DNS.', 'parent_id' => $parent->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id' => 'That commit is no longer available to reply to.']);

    expect($user->commits()->exists())->toBeFalse();
});

it('sends a commit from a signed request to the ai moderation the web uses', function (): void {
    config(['services.openai.key' => 'sk-test']);
    Queue::fake();

    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    $response = signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => 'It was DNS.']);

    $response->assertCreated();

    Queue::assertPushed(ModerateCommit::class, fn (ModerateCommit $job): bool => $job->commit->id === $response->json('data.id'));
});

it('collapses blank lines before measuring a body, like the web does', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => "It was DNS.\r\n\n\n\n\nIt always is."])
        ->assertCreated()
        ->assertJsonPath('data.body', "It was DNS.\n\nIt always is.");
});

it('counts each emoji as one character, like the web does', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => str_repeat('🚀', Commit::MAX_BODY_LENGTH)])
        ->assertCreated();

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => str_repeat('🚀', Commit::MAX_BODY_LENGTH + 1)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'The body field must not be greater than '.Commit::MAX_BODY_LENGTH.' characters.']);

    expect($user->commits()->sole()->body)->toBe(str_repeat('🚀', Commit::MAX_BODY_LENGTH));
});

it('limits a signed-in user to 10 commits a minute, in json', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);

    foreach (range(1, 10) as $attempt) {
        signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => "Commit {$attempt}"])->assertCreated();
    }

    signedApiRequest($keyPair, 'POST', route('api-commit.store'), ['body' => 'One too many'])
        ->assertTooManyRequests()
        ->assertExactJson(['message' => 'Slow down: 10 commits a minute. Even CI takes breaks.', 'code' => 'rate_limited'])
        ->assertHeader('Retry-After', '60');

    expect($user->commits()->count())->toBe(10);
});

it('deletes a commit of the signed-in user', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $commit = Commit::factory()->for($user)->create();

    signedApiRequest($keyPair, 'DELETE', route('api-commit.destroy', $commit))->assertNoContent();

    $this->assertSoftDeleted($commit);
});

it('may not delete a commit that is already deleted', function (): void {
    $user = User::factory()->create();
    $keyPair = apiKeyPairFor($user);
    $commit = tap(Commit::factory()->for($user)->create())->delete();

    signedApiRequest($keyPair, 'DELETE', route('api-commit.destroy', $commit))->assertNotFound();
});

it('may not delete the commits of others', function (): void {
    $keyPair = apiKeyPairFor(User::factory()->create());
    $commit = Commit::factory()->create();

    signedApiRequest($keyPair, 'DELETE', route('api-commit.destroy', $commit))->assertForbidden();

    $this->assertNotSoftDeleted($commit);
});

it('rejects commits and deletes that are not signed', function (): void {
    $commit = Commit::factory()->create();

    $this->postJson(route('api-commit.store'), ['body' => 'It was DNS.'])->assertUnauthorized();
    $this->deleteJson(route('api-commit.destroy', $commit))->assertUnauthorized();

    expect(Commit::query()->count())->toBe(1);
    $this->assertNotSoftDeleted($commit);
});
