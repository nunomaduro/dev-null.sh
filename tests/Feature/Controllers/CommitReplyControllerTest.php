<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Enums\CommitStatus;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('rejects non-text reply bodies with a validation error', function (mixed $body): void {
    $parent = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-reply.store', $parent), ['body' => $body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    expect($parent->replies()->count())->toBe(0)
        ->and(LinkPreview::query()->count())->toBe(0);
})->with([
    'list' => [['Hello, world!']],
    'object' => [['text' => 'Hello, world!']],
    'empty array' => [[]],
    'number' => [123],
    'boolean' => [true],
    'null' => [null],
]);

it('lists visible replies oldest first with their own reply counts', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();

    $second = Commit::factory()->for($parent, 'parent')->create(['body' => 'Second', 'created_at' => now()->subMinute()]);
    Commit::factory()->for($parent, 'parent')->create(['body' => 'First', 'created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($parent, 'parent')->for($viewer)->pending()->create(['body' => 'Mine, pending']);
    Commit::factory()->for($parent, 'parent')->pending()->create(['body' => 'Hidden']);
    Commit::factory()->for($second, 'parent')->count(2)->create();
    Commit::factory()->for($second, 'parent')->pending()->create();

    $response = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.body', 'First')
        ->assertJsonPath('data.1.body', 'Second')
        ->assertJsonPath('data.1.replies_count', 2)
        ->assertJsonPath('data.2.body', 'Mine, pending')
        ->assertJsonPath('data.0.user.id', fn (string $id): bool => $id !== '')
        ->assertJsonMissingPath('data.0.user.email')
        ->assertJsonPath('next_cursor', null);
});

it('lists replies 50 at a time with a cursor to the next page', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();

    Commit::factory()->for($parent, 'parent')->count(52)
        ->state(new Sequence(fn (Sequence $sequence): array => [
            'body' => "Reply {$sequence->index}",
            'created_at' => now()->subMinutes(100 - $sequence->index),
        ]))
        ->create();

    $first = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $first->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0.body', 'Reply 0')
        ->assertJsonPath('data.49.body', 'Reply 49')
        ->assertJsonPath('next_cursor', fn (string $cursor): bool => $cursor !== '');

    $second = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', [$parent, 'cursor' => $first->json('next_cursor')]));

    $second->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Reply 50')
        ->assertJsonPath('data.1.body', 'Reply 51')
        ->assertJsonPath('next_cursor', null);
});

it('orders replies created at the same time by id across pages', function (): void {
    $parent = Commit::factory()->create();
    $replies = Commit::factory()->for($parent, 'parent')->count(51)->create(['created_at' => now()->subMinute()]);

    $first = $this->actingAs(User::factory()->create())
        ->getJson(route('commit-reply.index', $parent));

    $second = $this->getJson(route('commit-reply.index', [$parent, 'cursor' => $first->json('next_cursor')]));

    expect([...$first->json('data.*.id'), ...$second->json('data.*.id')])
        ->toBe($replies->pluck('id')->sort()->values()->all());
});

it('only pages through replies visible to the viewer', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();

    Commit::factory()->for($parent, 'parent')->count(50)
        ->state(new Sequence(fn (Sequence $sequence): array => ['created_at' => now()->subMinutes(100 - $sequence->index)]))
        ->create();
    Commit::factory()->for($parent, 'parent')->pending()->count(3)->create(['created_at' => now()->subMinutes(10)]);
    Commit::factory()->for($parent, 'parent')->rejected()->create(['created_at' => now()->subMinutes(9)]);
    Commit::factory()->for($parent, 'parent')->for($viewer)->pending()->create(['body' => 'Mine, pending', 'created_at' => now()->subMinutes(8)]);

    $first = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $first->assertJsonCount(50, 'data')
        ->assertJsonPath('data.*.status', array_fill(0, 50, 'approved'));

    $second = $this->getJson(route('commit-reply.index', [$parent, 'cursor' => $first->json('next_cursor')]));

    $second->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Mine, pending')
        ->assertJsonPath('next_cursor', null);
});

it('lists deleted replies that still have replies as tombstones', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();

    $tombstone = Commit::factory()->for($parent, 'parent')->create(['body' => 'Gone', 'created_at' => now()->subMinutes(2)]);
    Commit::factory()->for($tombstone, 'parent')->create();
    $tombstone->delete();
    Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinute()])->delete();
    Commit::factory()->for($parent, 'parent')->create(['body' => 'Kept']);

    $response = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $tombstone->id)
        ->assertJsonPath('data.0.deleted_at', fn (string $deletedAt): bool => $deletedAt !== '')
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonMissingPath('data.0.body')
        ->assertJsonMissingPath('data.0.user')
        ->assertJsonMissingPath('data.0.user_id')
        ->assertJsonPath('data.1.body', 'Kept');
});

it('lists the replies of a deleted commit', function (): void {
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->create(['body' => 'Still here']);
    $parent->delete();

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Still here');
});

it('does not list replies of a commit that is not visible', function (): void {
    $parent = Commit::factory()->pending()->create();

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('commit-reply.index', $parent));

    $response->assertNotFound();
});

it('lists only approved replies to guests', function (): void {
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->create(['body' => 'Approved']);
    Commit::factory()->for($parent, 'parent')->pending()->create(['body' => 'Pending']);
    Commit::factory()->for($parent, 'parent')->rejected()->create(['body' => 'Rejected']);

    $response = $this->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Approved')
        ->assertJsonPath('data.0.is_saved', false);
});

it('returns not found to guests for the replies of a commit that is not approved', function (): void {
    $response = $this->getJson(route('commit-reply.index', Commit::factory()->pending()->create()));

    $response->assertNotFound();
});

it('leaves out replies by muted people and across blocks', function (): void {
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $blocker = User::factory()->create();
    $parent = Commit::factory()->create();

    $viewer->muting()->attach($muted);
    $blocker->blocking()->attach($viewer);

    $reply = Commit::factory()->for($parent, 'parent')->create();
    Commit::factory()->for($parent, 'parent')->for($muted)->create();
    Commit::factory()->for($parent, 'parent')->for($blocker)->create();

    $response = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id);
});

it('returns not found for replies to a commit across a block', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->create();

    $viewer->blocking()->attach($parent->user);

    $response = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $response->assertNotFound();
});

it('includes the link previews of replies', function (): void {
    $parent = Commit::factory()->create();
    Commit::factory()->for($parent, 'parent')->for(LinkPreview::factory()->fetched()->state(['title' => 'Reply link']))->create();

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonPath('data.0.link_preview.title', 'Reply link')
        ->assertJsonMissingPath('data.0.link_preview.url_hash');
});

it('may reply to a commit', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Agreed, ship it.']);

    $reply = $user->commits()->sole();

    $response->assertCreated()
        ->assertExactJson([
            'committed' => ['id' => $reply->id, 'body' => 'Agreed, ship it.'],
            'toast' => [
                'type' => 'info',
                'message' => 'Your commit is pending review. Everyone will see it once it is approved.',
            ],
        ]);

    expect($reply->parent_id)->toBe($parent->id)
        ->and($reply->status)->toBe(CommitStatus::Pending);
});

it('publishes replies of admins right away', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $user = User::factory()->create(['username' => 'nunomaduro']);
    $parent = Commit::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Shipped.']);

    $response->assertCreated()
        ->assertJsonMissingPath('toast');

    expect($user->commits()->sole()->status)->toBe(CommitStatus::Approved);
});

it('may reply to own pending commit', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->for($user)->pending()->create();

    $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Follow-up'])
        ->assertCreated();

    expect($parent->replies()->count())->toBe(1);
});

it('may not reply to a commit that is not visible', function (): void {
    $parent = Commit::factory()->pending()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Hello'])
        ->assertNotFound();

    expect($parent->replies()->count())->toBe(0);
});

it('may not reply to a deleted commit', function (): void {
    $parent = Commit::factory()->create();
    $parent->delete();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Hello'])
        ->assertNotFound();

    expect(Commit::query()->count())->toBe(0);
});

it('may not reply to a commit that does not exist', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-reply.store', '01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3'), ['body' => 'Hello'])
        ->assertNotFound();

    expect(Commit::query()->count())->toBe(0);
});

it('requires a reply body of 3 to 4,000 characters', function (string $body): void {
    $parent = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-reply.store', $parent), ['body' => $body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    expect($parent->replies()->count())->toBe(0);
})->with([
    'blank' => '   ',
    'too short' => '  ab  ',
    'too long' => str_repeat('a', 4001),
]);

it('may attach images to a reply', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => 'Look at this',
            'images' => [
                UploadedFile::fake()->image('first.jpg', 40, 30),
                UploadedFile::fake()->image('second.png', 10, 10),
            ],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($user->commits()->sole()->images)->toHaveCount(2)
        ->and(Storage::disk()->allFiles('commit-images'))->toHaveCount(2);
});

it('may reply with images without a body', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => '',
            'images' => [UploadedFile::fake()->image('screenshot.png', 40, 30)],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $reply = $user->commits()->sole();

    expect($reply->body)->toBe('')
        ->and($reply->parent_id)->toBe($parent->id)
        ->and($reply->images)->toHaveCount(1);
});

it('may describe the images attached to a reply', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => 'Offsite photo dump',
            'images' => [
                UploadedFile::fake()->image('cabin.jpg', 40, 30),
                UploadedFile::fake()->image('hike.jpg', 40, 30),
            ],
            'image_descriptions' => ['The cabin at sunrise, fog over the lake.', ''],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($user->commits()->sole()->images->pluck('description')->all())->toBe([
        'The cabin at sunrise, fog over the lake.',
        null,
    ]);
});

it('may not describe an image of a reply in more than a thousand characters', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => 'Too long a description',
            'images' => [UploadedFile::fake()->image('photo.jpg', 10, 10)],
            'image_descriptions' => [str_repeat('a', 1001)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image_descriptions.0');

    expect($user->commits()->count())->toBe(0);
});

it('may only attach jpeg, png or webp images to a reply', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => 'Not an image',
            'images' => [UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images.0');

    expect($user->commits()->count())->toBe(0);
});

it('rate limits replies', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)
            ->postJson(route('commit-reply.store', $parent), ['body' => "Reply {$i}"])
            ->assertCreated();
    }

    $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'One too many'])
        ->assertTooManyRequests()
        ->assertExactJson(['message' => 'Slow down: 10 commits a minute. Even CI takes breaks.']);

    expect($user->commits()->count())->toBe(10);
});

it('requires authentication to reply', function (): void {
    $parent = Commit::factory()->create();

    $this->postJson(route('commit-reply.store', $parent), ['body' => 'Hello'])
        ->assertUnauthorized();

    expect($parent->replies()->count())->toBe(0);
});

it('may delete own reply', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $this->actingAs($reply->user)
        ->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertOk()
        ->assertJsonPath('deleted.id', $reply->id);

    $this->assertSoftDeleted($reply);
});

it('may delete own reply to a deleted commit', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $parent->delete();

    $this->actingAs($reply->user)
        ->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertOk()
        ->assertJsonPath('deleted.id', $reply->id);

    $this->assertSoftDeleted($reply);
});

it('may not delete replies of other users', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertForbidden();

    $this->assertNotSoftDeleted($reply);
});

it('may not delete a reply that is already deleted', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $reply->delete();

    $this->actingAs($reply->user)
        ->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertNotFound();
});

it('may not delete a reply through another commit', function (): void {
    $reply = Commit::factory()->for(Commit::factory(), 'parent')->create();

    $this->actingAs($reply->user)
        ->deleteJson(route('commit-reply.destroy', [Commit::factory()->create(), $reply]))
        ->assertNotFound();

    $this->assertNotSoftDeleted($reply);
});

it('requires authentication to delete a reply', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $this->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertUnauthorized();

    $this->assertNotSoftDeleted($reply);
});

it('may not reply across a block in either direction', function (bool $replierBlocks): void {
    $replier = User::factory()->create();
    $parent = Commit::factory()->create();

    $replierBlocks ? $replier->blocking()->attach($parent->user) : $parent->user->blocking()->attach($replier);

    $response = $this->actingAs($replier)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Hello']);

    $response->assertNotFound();

    expect($parent->replies()->count())->toBe(0)
        ->and($parent->user->notifications()->exists())->toBeFalse();
})->with([true, false]);

it('may reply to a muted user without them knowing', function (): void {
    $replier = User::factory()->create();
    $parent = Commit::factory()->create();

    $replier->muting()->attach($parent->user);

    $this->actingAs($replier)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Hello'])
        ->assertCreated();

    expect($parent->replies()->count())->toBeOne();
});

it('keeps the chosen link card and image description on a json reply', function (array $choice, ?string $url): void {
    Storage::fake();
    Queue::fake([UnfurlLink::class]);

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit-reply.store', $parent), [
            'body' => 'Photo at https://example.com/first and docs at https://example.com/second',
            'images' => [UploadedFile::fake()->image('photo.jpg', 40, 30)],
            'image_descriptions' => ['The team at the cabin.'],
            ...$choice,
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $reply = $user->commits()->sole();

    expect($reply->parent_id)->toBe($parent->id)
        ->and($reply->images->sole()->description)->toBe('The team at the cabin.')
        ->and($reply->linkPreview?->url)->toBe($url);

    if ($url === null) {
        Queue::assertNotPushed(UnfurlLink::class);
    } else {
        Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($reply->linkPreview));
    }
})->with([
    'default first link' => [[], 'https://example.com/first'],
    'chosen second link' => [['link_preview_url' => 'https://example.com/second'], 'https://example.com/second'],
    'no card' => [['link_preview_url' => ''], null],
]);

it('rejects a json reply card that is not a link in its body', function (string $url): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), [
            'body' => 'Read https://example.com/first and `https://example.com/code`',
            'link_preview_url' => $url,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['link_preview_url' => 'The link card must be one of the links in the body.']);

    expect($user->commits()->count())->toBe(0)
        ->and($parent->replies()->count())->toBe(0);
})->with([
    'another url' => 'https://evil.example.com',
    'a url in code' => 'https://example.com/code',
    'part of a url' => 'https://example.com',
]);

it('includes the star count of replies and whether the viewer starred them', function (): void {
    $viewer = User::factory()->create();
    $parent = Commit::factory()->create();
    $starred = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinutes(2)]);
    $other = Commit::factory()->for($parent, 'parent')->create(['created_at' => now()->subMinute()]);

    $viewer->starredCommits()->attach($starred);
    User::factory()->create()->starredCommits()->attach([$starred->id, $other->id]);

    $response = $this->actingAs($viewer)
        ->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonPath('data.0.stars_count', 2)
        ->assertJsonPath('data.0.starred', true)
        ->assertJsonPath('data.1.stars_count', 1)
        ->assertJsonPath('data.1.starred', false);
});

it('includes the star count of replies for guests without marking any as starred', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    User::factory()->create()->starredCommits()->attach($reply);

    $response = $this->getJson(route('commit-reply.index', $parent));

    $response->assertOk()
        ->assertJsonPath('data.0.stars_count', 1)
        ->assertJsonPath('data.0.starred', false);
});

it('returns 404 to guests for replies of an unapproved tombstone after account deletion', function (CommitStatus $status): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($user)->for($root, 'parent')->create(['status' => $status]);
    Commit::factory()->for($parent, 'parent')->create();
    resolve(DeleteUser::class)->handle($user);

    $response = $this->getJson(route('commit-reply.index', $parent));

    $response->assertNotFound();
})->with([
    'pending' => [CommitStatus::Pending],
    'rejected' => [CommitStatus::Rejected],
]);

it('keeps account deletion tombstones in threads while filtering silenced authors', function (string $relation, bool $viewerActs): void {
    $viewer = User::factory()->create();
    $silenced = User::factory()->create();
    [$actor, $subject] = $viewerActs ? [$viewer, $silenced] : [$silenced, $viewer];
    $actor->{$relation}()->attach($subject);

    $author = User::factory()->create();
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($author)->for($root, 'parent')->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    Commit::factory()->for($silenced)->for($parent, 'parent')->create();
    $hiddenParent = Commit::factory()->for($author)->for($root, 'parent')->create();
    Commit::factory()->for($silenced)->for($hiddenParent, 'parent')->create();
    resolve(DeleteUser::class)->handle($author);

    $this->actingAs($viewer)->getJson(route('commit-reply.index', $root))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonMissingPath('data.0.user')
        ->assertJsonMissingPath('data.0.body');

    $this->getJson(route('commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id);
})->with([
    'muted author' => ['muting', true],
    'blocked author' => ['blocking', true],
    'blocking author' => ['blocking', false],
]);

it('keeps approved account deletion tombstones readable to guests without exposing the deleted author', function (): void {
    $user = User::factory()->create(['username' => 'deletedauthor']);
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($user)->for($root, 'parent')->create(['body' => 'Deleted body']);
    $reply = Commit::factory()->for($parent, 'parent')->create();
    resolve(DeleteUser::class)->handle($user);

    $response = $this->get(route('commit.show', [$reply->user, $reply]));

    $response->assertOk()
        ->assertSee('<meta property="og:title" content="'.e($reply->user->nameWithHandle()).' on /dev/null: “', false)
        ->assertSee('"@type":"SocialMediaPosting"', false)
        ->assertDontSee('"isPartOf":', false)
        ->assertDontSee('deletedauthor')
        ->assertDontSee('Deleted body')
        ->assertInertia(fn ($page) => $page
            ->where('ancestors.1.id', $parent->id)
            ->missing('ancestors.1.body')
            ->missing('ancestors.1.user'));

    $this->getJson(route('commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonPath('data.0.id', $reply->id);
});

it('keeps quotes out of the json reply endpoint', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();
    $quoted = Commit::factory()->create();

    $this->actingAs($user)
        ->postJson(route('commit-reply.store', $parent), ['body' => 'Reply only', 'quoted_commit_id' => $quoted->id])
        ->assertCreated();

    expect($user->commits()->sole())
        ->parent_id->toBe($parent->id)
        ->quoted_commit_id->toBeNull();
});

it('lists quotes alongside replies without embedding the quoted commit', function (): void {
    $parent = Commit::factory()->create();
    $quote = Commit::factory()->for($parent, 'quotedCommit')->create(['created_at' => now()->subMinute()]);
    $reply = Commit::factory()->for($parent, 'parent')->for($parent, 'quotedCommit')->create();
    Commit::factory()->for($quote, 'quotedCommit')->create();
    Commit::factory()->create();

    $this->getJson(route('commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $quote->id)
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonMissingPath('data.0.quoted_commit')
        ->assertJsonPath('data.1.id', $reply->id);

    $this->get(route('commit.show', [$parent->user, $parent]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.replies_count', 2)
            ->has('replies.data', 2)
            ->missing('replies.data.0.quoted_commit'));

    $this->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('commit.quoted_commit.id', $parent->id));
});

it('applies reply visibility rules to quotes and their counts', function (): void {
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $blocked = User::factory()->create();
    $viewer->muting()->attach($muted);
    $blocked->blocking()->attach($viewer);
    $parent = Commit::factory()->create();
    $quote = Commit::factory()->for($parent, 'quotedCommit')->create();
    $ownPending = Commit::factory()->for($parent, 'quotedCommit')->for($viewer)->pending()->create();
    Commit::factory()->for($parent, 'quotedCommit')->pending()->create();
    Commit::factory()->for($parent, 'quotedCommit')->rejected()->create();
    Commit::factory()->for($parent, 'quotedCommit')->for($muted)->create();
    Commit::factory()->for($parent, 'quotedCommit')->for($blocked)->create();
    Commit::factory()->for($parent, 'quotedCommit')->create()->delete();

    $this->actingAs($viewer)->getJson(route('commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.id', [$quote->id, $ownPending->id]);

    expect(Commit::query()->withVisibleReplyCount($viewer)->findOrFail($parent->id)->replies_count)->toBe(2);
});

it('keeps a deleted reply as a tombstone when it has a visible quote', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $quote = Commit::factory()->for($reply, 'quotedCommit')->create();
    $reply->delete();

    $this->getJson(route('commit-reply.index', $parent))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id)
        ->assertJsonPath('data.0.replies_count', 1)
        ->assertJsonMissingPath('data.0.body');

    $this->getJson(route('commit-reply.index', $reply))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $quote->id);
});

it('may delete an own quote listed as a reply', function (bool $parentDeleted): void {
    $parent = Commit::factory()->create();
    $quote = Commit::factory()->for($parent, 'quotedCommit')->create();

    if ($parentDeleted) {
        $parent->delete();
    }

    $this->actingAs($quote->user)
        ->deleteJson(route('commit-reply.destroy', [$parent, $quote]))
        ->assertOk()
        ->assertJsonPath('deleted.id', $quote->id);

    $this->assertSoftDeleted($quote);
})->with([false, true]);

it('may not delete an own quote through another commit', function (): void {
    $quote = Commit::factory()->for(Commit::factory(), 'quotedCommit')->create();

    $this->actingAs($quote->user)
        ->deleteJson(route('commit-reply.destroy', [Commit::factory()->create(), $quote]))
        ->assertNotFound();

    $this->assertNotSoftDeleted($quote);
});

it('may not delete quotes of other users', function (): void {
    $parent = Commit::factory()->create();
    $quote = Commit::factory()->for($parent, 'quotedCommit')->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('commit-reply.destroy', [$parent, $quote]))
        ->assertForbidden();

    $this->assertNotSoftDeleted($quote);
});

it('returns an undo deadline for a json deletion and restores its thread count', function (string $relation): void {
    $this->travelTo('2026-10-16 12:00:00 UTC');

    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, $relation)->create();
    $user = $reply->user;

    $this->actingAs($user)
        ->deleteJson(route('commit-reply.destroy', [$parent, $reply]))
        ->assertOk()
        ->assertExactJson(['deleted' => [
            'id' => $reply->id,
            'parent_id' => $reply->parent_id,
            'deleted_at' => '2026-10-16T12:00:00.000000Z',
            'expires_at' => '2026-10-16T12:00:30.000000Z',
            'server_time' => '2026-10-16T12:00:00.000000Z',
        ]]);

    $this->assertSoftDeleted($reply);
    $this->getJson(route('commit-reply.index', $parent))->assertJsonCount(0, 'data');

    $this->travel(29)->seconds();
    $this->post(route('commit-restoration.store', $reply))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('restored.id', $reply->id)
        ->assertInertiaFlash('restored.'.($relation === 'parent' ? 'parent' : 'quoted_commit').'.replies_count', 1);

    $this->assertNotSoftDeleted($reply);
    $this->getJson(route('commit-reply.index', $parent))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id);
})->with(['reply' => 'parent', 'quote' => 'quotedCommit']);

it('lists the newest reply with a cursor to older replies when requested', function (): void {
    $parent = Commit::factory()->create();
    $replies = Commit::factory()->for($parent, 'parent')->count(4)->create(['created_at' => now()->subMinute()]);
    $expected = $replies->pluck('id')->sortDesc()->values()->all();

    $first = $this->getJson(route('commit-reply.index', [$parent, 'newest' => 1, 'limit' => 1]));
    $first->assertOk()->assertJsonCount(1, 'data');
    expect($first->json('data.*.id'))->toBe(array_slice($expected, 0, 1));

    $second = $this->getJson(route('commit-reply.index', [$parent, 'newest' => 1, 'cursor' => $first->json('next_cursor')]));
    $second->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('next_cursor', null);
    expect($second->json('data.*.id'))->toBe(array_slice($expected, 1));
});

it('may attach a giphy gif to a reply', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $response = $this->actingAs($user)->post(route('commit-reply.store', $parent), [
        'body' => 'Replying with a gif',
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ]);

    $response->assertSessionHasNoErrors();

    expect($user->commits()->sole()->giphy_id)->toBe('xT9IgzoKnwFNmISR8I');
});

it('rejects a reply gif alongside uploaded images', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)->post(route('commit-reply.store', $parent), [
        'body' => 'Both at once',
        'images' => [UploadedFile::fake()->image('cabin.jpg', 40, 30)],
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasErrors('giphy_id');
});

it('rejects a bad gif media url on a reply', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)->post(route('commit-reply.store', $parent), [
        'body' => 'Nice try',
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://evil.example/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasErrors('giphy_url');
});
