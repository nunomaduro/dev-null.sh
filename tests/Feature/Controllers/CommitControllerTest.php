<?php

declare(strict_types=1);

use App\Actions\CreateQuoteNotifications;
use App\Actions\DeleteUser;
use App\Enums\CommitStatus;
use App\Enums\Log;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('may create a commit', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Hello, devnull!',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following);

    expect($user->commits()->sole()->body)->toBe('Hello, devnull!');
});

it('flashes the created commit', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'Initial commit']);

    $commit = $user->commits()->sole();

    $response->assertInertiaFlash('committed', [
        'id' => $commit->id,
        'body' => 'Initial commit',
    ]);
});

it('does not count the authenticated user relations when redirecting after a commit', function (): void {
    $user = User::factory()->create();

    DB::enableQueryLog();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Initial commit',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following);

    expect(array_column(DB::getQueryLog(), 'query'))
        ->not->toBeEmpty()
        ->each->not->toContain('count(*)');
});

it('rejects non-text commit bodies with a validation error', function (mixed $body): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('commit.store'), ['body' => $body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    expect(Commit::query()->count())->toBe(0)
        ->and(LinkPreview::query()->count())->toBe(0);
})->with([
    'list' => [['Hello, world!']],
    'object' => [['text' => 'Hello, world!']],
    'empty array' => [[]],
    'number' => [123],
    'boolean' => [true],
    'null' => [null],
]);

it('requires a body', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => '   ',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasErrors('body');

    expect(Commit::query()->count())->toBe(0);
});

it('requires the body to be at least 3 characters', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => '  ab  ',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasErrors('body');

    expect(Commit::query()->count())->toBe(0);
});

it('requires the body to be at most 4,000 characters', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => str_repeat('a', 4001),
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasErrors('body');

    expect(Commit::query()->count())->toBe(0);
});

it('accepts a body of 4,000 characters, counting multibyte characters as one', function (): void {
    $body = str_repeat('é', 3998).'🚀🚀';

    $this->actingAs(User::factory()->create())
        ->post(route('commit.store'), ['body' => $body])
        ->assertSessionHasNoErrors();

    expect(Commit::query()->sole()->body)->toBe($body);
});

it('collapses runs of blank lines in the prose before measuring the body, leaving code alone', function (): void {
    $code = "```python\ndef a():\n    pass\n\n\ndef b():\n    pass\n```";
    $body = "First\n\n\n\n\nSecond \n \n\nThird\n{$code}\n\n\n".str_repeat('a', 3900);

    $this->actingAs(User::factory()->create())
        ->post(route('commit.store'), ['body' => $body])
        ->assertSessionHasNoErrors();

    expect(Commit::query()->sole()->body)->toBe("First\n\nSecond\n\nThird\n{$code}\n\n".str_repeat('a', 3900));
});

it('rate limits commit creation', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)
            ->fromRoute('log.show', Log::Following)
            ->post(route('commit.store'), ['body' => "Commit {$i}"])
            ->assertRedirectToRoute('log.show', Log::Following);
    }

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'One too many']);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Slow down: 10 commits a minute. Even CI takes breaks.',
        ]);

    expect($user->commits()->count())->toBe(10);
});

it('requires authentication to create a commit', function (): void {
    $response = $this->post(route('commit.store'), [
        'body' => 'Initial commit',
    ]);

    $response->assertRedirectToRoute('home');

    expect(Commit::query()->count())->toBe(0);
});

it('may delete own commit', function (): void {
    $commit = Commit::factory()->create();

    $response = $this->actingAs($commit->user)
        ->fromRoute('log.show', Log::Following)
        ->delete(route('commit.destroy', $commit));

    $response->assertRedirectToRoute('log.show', Log::Following);

    $this->assertSoftDeleted($commit);
});

it('keeps the replies of others when deleting own commit', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();

    $this->actingAs($commit->user)
        ->fromRoute('log.show', Log::Following)
        ->delete(route('commit.destroy', $commit));

    $this->assertSoftDeleted($commit);

    $this->assertNotSoftDeleted($reply);
});

it('may not delete a commit that is already deleted', function (): void {
    $commit = Commit::factory()->create();
    $commit->delete();

    $response = $this->actingAs($commit->user)
        ->fromRoute('log.show', Log::Following)
        ->delete(route('commit.destroy', $commit));

    $response->assertNotFound();
});

it('may not delete commits of other users', function (): void {
    $commit = Commit::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Following)
        ->delete(route('commit.destroy', $commit));

    $response->assertForbidden();

    $this->assertNotSoftDeleted($commit);
});

it('may delete own commit from its page', function (): void {
    $commit = Commit::factory()->create();

    $response = $this->actingAs($commit->user)
        ->from(route('commit.show', [$commit->user, $commit]))
        ->delete(route('commit.destroy', $commit));

    $response->assertRedirectToRoute('user.show', $commit->user);

    $this->assertSoftDeleted($commit);
});

it('may delete own commit while viewing one of its photos', function (): void {
    $commit = Commit::factory()->create();
    CommitImage::factory()->for($commit)->create();

    $response = $this->actingAs($commit->user)
        ->from(route('commit-photo.show', [$commit->user, $commit, 1]))
        ->delete(route('commit.destroy', $commit));

    $response->assertRedirectToRoute('user.show', $commit->user);

    $this->assertSoftDeleted($commit);
});

it('tells users their commit is pending review', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'Initial commit']);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertInertiaFlash('toast.type', 'info');

    expect($user->commits()->sole()->status)->toBe(CommitStatus::Pending);
});

it('publishes commits of admins right away', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $user = User::factory()->create(['username' => 'nunomaduro']);

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'Initial commit']);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertInertiaFlashMissing('toast');

    expect($user->commits()->sole()->status)->toBe(CommitStatus::Approved);
});

it('does not create replies', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), [
            'body' => 'Agreed, ship it.',
            'parent_id' => $parent->id,
        ])
        ->assertRedirectToRoute('log.show', Log::Trending);

    expect($user->commits()->sole()->parent_id)->toBeNull()
        ->and($parent->replies()->count())->toBe(0);
});

it('may quote a commit', function (): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), [
            'body' => 'Required reading.',
            'quoted_commit_id' => $quoted->id,
        ]);

    $response->assertRedirectToRoute('log.show', Log::Trending)
        ->assertSessionHasNoErrors();

    expect($user->commits()->sole())
        ->quoted_commit_id->toBe($quoted->id)
        ->parent_id->toBeNull();
});

it('may quote own pending commit', function (): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->for($user)->pending()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'Follow-up', 'quoted_commit_id' => $quoted->id])
        ->assertSessionHasNoErrors();

    expect(Commit::query()->where('quoted_commit_id', $quoted->id)->count())->toBe(1);
});

it('may not quote a commit that is not available', function (Closure $factory): void {
    $quoted = $factory();

    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), ['body' => 'Required reading.', 'quoted_commit_id' => $quoted->id]);

    $response->assertSessionHasErrors(['quoted_commit_id' => 'That commit is no longer available to quote.']);

    expect(Commit::query()->whereNotNull('quoted_commit_id')->count())->toBe(0);
})->with([
    'pending' => [fn (): Commit => Commit::factory()->pending()->create()],
    'rejected' => [fn (): Commit => Commit::factory()->rejected()->create()],
    'deleted' => [function (): Commit {
        $commit = Commit::factory()->create();
        $commit->delete();

        return $commit;
    }],
]);

it('may not quote a commit that does not exist', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), ['body' => 'Hello', 'quoted_commit_id' => '01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3']);

    $response->assertSessionHasErrors('quoted_commit_id');
});

it('may not quote a commit in a reply', function (): void {
    $commit = Commit::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), ['body' => 'Hello', 'parent_id' => $commit->id, 'quoted_commit_id' => $commit->id]);

    $response->assertSessionHasErrors('quoted_commit_id');

    expect(Commit::query()->count())->toBe(1);
});

it('shows the quoted commits of a commit and its ancestors with their authors', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $root = Commit::factory()->for(Commit::factory()->state(['body' => 'Quoted by the root']), 'quotedCommit')->create();
    $commit = Commit::factory()->for($root, 'parent')->create();
    $focused = Commit::factory()->for(Commit::factory()->for(User::factory()->state(['username' => 'octocat']))->state(['body' => 'Quoted by the focused commit']), 'quotedCommit')->create();

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit', null)
            ->where('ancestors.0.quoted_commit.body', 'Quoted by the root')
            ->where('ancestors.0.quoted_commit.user.username', $root->quotedCommit->user->username));

    $this->get(route('commit.show', [$focused->user, $focused]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit_id', $focused->quoted_commit_id)
            ->where('commit.quoted_commit.body', 'Quoted by the focused commit')
            ->where('commit.quoted_commit.user.username', 'octocat')
            ->missing('commit.quoted_commit.user.email'));
})->with(['guest' => false, 'signed in' => true]);

it('shows a deleted quoted commit as a tombstone', function (bool $signedIn): void {
    $quoted = Commit::factory()->create(['body' => 'Deleted words']);
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();
    $quoted->delete();

    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.id', $quoted->id)
            ->where('commit.quoted_commit.deleted_at', fn (string $deletedAt): bool => $deletedAt !== '')
            ->missing('commit.quoted_commit.body')
            ->missing('commit.quoted_commit.user'))
        ->assertDontSee('Deleted words');
})->with(['guest' => false, 'signed in' => true]);

it('keeps account deletion quote tombstones private and removes their notifications', function (CommitStatus $status, bool $signedIn): void {
    $author = User::factory()->create(['username' => 'deletedquoteauthor']);
    $root = Commit::factory()->create();
    $quoted = Commit::factory()->for($author)->for($root, 'parent')->create(['body' => 'Erased quoted words']);
    Commit::factory()->for($quoted, 'parent')->create();
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();

    $notifications = resolve(CreateQuoteNotifications::class);
    $notifications->handle($quote);

    expect($author->notifications()->sole()->commit_id)->toBe($quote->id);

    $quoted->update(['status' => $status]);
    resolve(DeleteUser::class)->handle($author);
    $notifications->handle($quote);

    expect(Notification::query()->exists())->toBeFalse()
        ->and($quote->refresh()->quoted_commit_id)->toBe($quoted->id);

    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $response = $this->get(route('commit.show', [$quote->user, $quote]));

    $response->assertOk()
        ->assertDontSee('deletedquoteauthor')
        ->assertDontSee('Erased quoted words')
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit_id', $quoted->id)
            ->missing('commit.quoted_commit.body')
            ->missing('commit.quoted_commit.user'));

    if ($status === CommitStatus::Approved) {
        $response->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.id', $quoted->id)
            ->where('commit.quoted_commit.deleted_at', fn (string $deletedAt): bool => $deletedAt !== ''));
    } else {
        $response->assertInertia(fn ($page) => $page->where('commit.quoted_commit', null));
    }
})->with(CommitStatus::cases())->with(['guest' => false, 'signed in' => true]);

it('never shows a quoted commit that is no longer visible to the viewer', function (CommitStatus $status, bool $signedIn): void {
    $quoted = Commit::factory()->create(['body' => 'Words under review']);
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();
    $quoted->update(['status' => $status]);

    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit_id', $quoted->id)
            ->where('commit.quoted_commit', null))
        ->assertDontSee('Words under review');

    $this->actingAs($quoted->user)
        ->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('commit.quoted_commit.body', 'Words under review'));
})->with([CommitStatus::Pending, CommitStatus::Rejected])->with(['guest' => false, 'signed in' => true]);

it('may not quote across a block in either direction', function (bool $quoterBlocks): void {
    $quoter = User::factory()->create();
    $quoted = Commit::factory()->create();

    $quoterBlocks ? $quoter->blocking()->attach($quoted->user) : $quoted->user->blocking()->attach($quoter);

    $this->actingAs($quoter)
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), ['body' => 'Hello', 'quoted_commit_id' => $quoted->id])
        ->assertSessionHasErrors(['quoted_commit_id' => 'That commit is no longer available to quote.']);

    expect($quoted->quotes()->count())->toBe(0)
        ->and($quoted->user->notifications()->exists())->toBeFalse();
})->with([true, false]);

it('hides the quoted commit across a block in either direction', function (bool $viewerBlocks): void {
    $viewer = User::factory()->create();
    $quoted = Commit::factory()->create(['body' => 'Blocked words']);
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();

    $viewerBlocks ? $viewer->blocking()->attach($quoted->user) : $quoted->user->blocking()->attach($viewer);

    $this->actingAs($viewer)
        ->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit_id', $quoted->id)
            ->where('commit.quoted_commit', null))
        ->assertDontSee('Blocked words');
})->with([true, false]);

it('does not show a commit across a block in either direction', function (bool $viewerBlocks): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create();

    $viewerBlocks ? $viewer->blocking()->attach($commit->user) : $commit->user->blocking()->attach($viewer);

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertNotFound();
})->with([true, false]);

it('shows a muted user commit when opened directly, without their replies', function (): void {
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $commit = Commit::factory()->for($muted)->create();
    Commit::factory()->for($commit, 'parent')->for($muted)->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();

    $viewer->muting()->attach($muted);

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$muted, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.id', $commit->id)
            ->where('commit.replies_count', 1)
            ->has('replies.data', 1)
            ->where('replies.data.0.id', $reply->id));
});

it('shows a commit with its ancestors and reply count', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->create();
    $commit = Commit::factory()->for($root, 'parent')->create(['body' => 'Focused']);
    Commit::factory()->for($commit, 'parent')->count(2)->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('commit/show')
            ->where('commit.id', $commit->id)
            ->where('commit.body', 'Focused')
            ->where('commit.user.username', $commit->user->username)
            ->where('commit.replies_count', 2)
            ->has('replies.data', 2)
            ->where('replies.data.0.user.username', fn (string $username): bool => $username !== '')
            ->where('replies.next_cursor', null)
            ->has('ancestors', 1)
            ->where('ancestors.0.id', $root->id)
            ->where('ancestors.0.user.username', $root->user->username)
            ->missing('commit.user.email'));
});

it('shows the commit and its replies split into prose and highlighted code', function (): void {
    $commit = Commit::factory()->create(['body' => "Run `make` :fire\n```php\n\$name = '<b>';\n```"]);
    Commit::factory()->for($commit, 'parent')->create(['body' => 'try `make test`']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.body_segments', [
                ['type' => 'text', 'text' => 'Run '],
                ['type' => 'code', 'code' => 'make'],
                ['type' => 'text', 'text' => ' :fire'],
                ['type' => 'block', 'language' => 'php', 'html' => '<span class="hl-variable">$name</span> = <span class="hl-value">\'&lt;b&gt;\'</span>;'],
            ])
            ->where('replies.data.0.body_segments.1', ['type' => 'code', 'code' => 'make test'])
            ->missing('replies.data.0.body_segments.2'));
});

it('shows the star count and whether the viewer starred the commit and its ancestors', function (): void {
    $viewer = User::factory()->create();
    $root = Commit::factory()->create();
    $commit = Commit::factory()->for($root, 'parent')->create();

    $viewer->starredCommits()->attach($root);
    User::factory()->create()->starredCommits()->attach([$root->id, $commit->id]);

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.stars_count', 1)
            ->where('commit.starred', false)
            ->where('ancestors.0.stars_count', 2)
            ->where('ancestors.0.starred', true));
});

it('shows the star count and whether the viewer starred each reply', function (): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();

    $viewer->starredCommits()->attach($reply);
    User::factory()->create()->starredCommits()->attach($reply);

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('replies.data.0.stars_count', 2)
            ->where('replies.data.0.starred', true));
});

it('shows the first 50 replies of a commit with a cursor to the rest', function (): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create();

    Commit::factory()->for($commit, 'parent')->count(51)
        ->state(new Sequence(fn (Sequence $sequence): array => [
            'body' => "Reply {$sequence->index}",
            'created_at' => now()->subMinutes(100 - $sequence->index),
        ]))
        ->create();
    Commit::factory()->for($commit, 'parent')->pending()->create(['created_at' => now()->subMinutes(99)]);

    $response = $this->actingAs($viewer)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.replies_count', 51)
            ->has('replies.data', 50)
            ->where('replies.data.0.body', 'Reply 0')
            ->where('replies.data.1.body', 'Reply 1')
            ->where('replies.data.49.body', 'Reply 49')
            ->where('replies.next_cursor', fn (string $cursor): bool => $cursor !== ''));
});

it('reloads the commit alone without loading its thread', function (): void {
    $viewer = User::factory()->create();
    $queries = function (Commit $commit) use ($viewer): int {
        $this->actingAs($viewer)
            ->get(route('commit.show', [$commit->user, $commit]))
            ->assertOk()
            ->assertInertia(function ($page): void {
                DB::flushQueryLog();
                DB::enableQueryLog();

                $page->reloadOnly('commit', fn ($reload) => $reload->missing('replies')->missing('ancestors')->missing('series'));

                DB::disableQueryLog();
            });

        return count(DB::getQueryLog());
    };
    $alone = Commit::factory()->create();
    $inThread = Commit::factory()->for(Commit::factory()->for(Commit::factory(), 'parent'), 'parent')->create();
    Commit::factory()->for($inThread, 'parent')->count(3)->create();

    expect($queries($inThread))->toBe($queries($alone));
});

it('builds the link preview only for full page loads', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    $queries = function (array $headers) use ($commit): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('commit.show', [$commit->user, $commit]), $headers)->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queries(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request())]))
        ->toBeLessThan($queries([]));
});

it('shows own pending commit', function (): void {
    $commit = Commit::factory()->pending()->create();

    $response = $this->actingAs($commit->user)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('commit.status', 'pending'));
});

it('does not show a commit that is not visible', function (): void {
    $commit = Commit::factory()->pending()->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertNotFound();
});

it('does not show a deleted commit', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    $commit->delete();

    $response = $this->actingAs($commit->user)
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertNotFound();
});

it('shows deleted ancestors and replies as tombstones', function (): void {
    $root = Commit::factory()->create();
    $deletedAncestor = Commit::factory()->for($root, 'parent')->create();
    $commit = Commit::factory()->for($deletedAncestor, 'parent')->create();
    $deletedReply = Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($deletedReply, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->create()->delete();
    $deletedAncestor->delete();
    $deletedReply->delete();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.replies_count', 1)
            ->has('ancestors', 2)
            ->where('ancestors.0.id', $root->id)
            ->where('ancestors.1.id', $deletedAncestor->id)
            ->missing('ancestors.1.body')
            ->missing('ancestors.1.body_segments')
            ->missing('ancestors.1.user')
            ->has('replies.data', 1)
            ->where('replies.data.0.id', $deletedReply->id)
            ->where('replies.data.0.replies_count', 1)
            ->missing('replies.data.0.body'));
});

it('does not show a commit under another username', function (): void {
    $commit = Commit::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [User::factory()->create(), $commit]));

    $response->assertNotFound();
});

it('shows guests the commit with its approved replies and a link preview', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat']);
    $commit = Commit::factory()->for($user)->create(['body' => "Shipped the new parser.\n\nIt is twice as fast."]);
    $commit->stars()->attach(User::factory()->create());
    Commit::factory()->for($commit, 'parent')->create(['body' => 'A reply everyone may read']);
    Commit::factory()->for($commit, 'parent')->pending()->create(['body' => 'A pending reply only its author may read']);
    Commit::factory()->for($user)->create(['body' => 'Another commit, not part of this thread']);

    $response = $this->get(route('commit.show', [$user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('commit/show')
            ->where('commit.id', $commit->id)
            ->where('commit.replies_count', 1)
            ->where('commit.stars_count', 1)
            ->where('commit.starred', false)
            ->where('commit.is_saved', false)
            ->has('ancestors', 0)
            ->has('replies.data', 1)
            ->where('replies.data.0.body', 'A reply everyone may read'))
        ->assertSee('<title>The Octocat (@octocat) on /dev/null: “Shipped the new parser. It is twice as fast.”</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('commit.show', [$user, $commit]).'">', false)
        ->assertSee('<meta name="twitter:title" content="The Octocat (@octocat) on /dev/null: “Shipped the new parser. It is twice as fast.”">', false)
        ->assertSee('<meta property="article:published_time" content="'.$commit->created_at->toIso8601String().'">', false)
        ->assertSee('"@type":"SocialMediaPosting"', false)
        ->assertSee('"author":{"@type":"Person","name":"The Octocat","alternateName":"@octocat","url":"'.route('user.show', $user).'"}', false)
        ->assertSee('<meta property="og:type" content="article">', false)
        ->assertSee('<meta property="og:title" content="The Octocat (@octocat) on /dev/null: “Shipped the new parser. It is twice as fast.”">', false)
        ->assertSee('<meta property="og:description" content="Shipped the new parser. It is twice as fast.">', false)
        ->assertSee('<meta property="og:url" content="'.route('commit.show', [$user, $commit]).'">', false)
        ->assertSee('<meta property="og:image" content="'.route('commit-open-graph-image.show', [$user, $commit]).'?v=', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertDontSee(url('/og.png'))
        ->assertDontSee('A pending reply only its author may read')
        ->assertDontSee('Another commit, not part of this thread');

    $this->assertGuest();

    expect(session('url.intended'))->toBe(route('commit.show', [$user, $commit]));
});

it('trims long commits in the link preview description', function (): void {
    $commit = Commit::factory()->create(['body' => str_repeat('Writing PHP on purpose. ', 9).'The end is not described.']);

    $response = $this->get(route('commit.show', [$commit->user, $commit]));

    $description = Str::betweenFirst((string) $response->getContent(), '<meta property="og:description" content="', '"');

    expect($description)->toEndWith('…')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(201)
        ->and($description)->not->toContain('The end');
});

it('shortens long urls in the link preview description', function (): void {
    $commit = Commit::factory()->create(['body' => 'A lot came! https://github.com/nunomaduro/dev-null.sh/pulls?q=sort%3Aupdated-desc+is%3Apr']);

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="A lot came! github.com/nunomaduro/dev-nul…">', false);
});

it('escapes the commit in the link preview', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => '"><script>alert(1)</script>']);
    $commit = Commit::factory()->for($user)->create(['body' => '<img src=x onerror=alert(1)>']);

    $this->get(route('commit.show', [$user, $commit]))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee('<meta property="og:description" content="&lt;img src=x onerror=alert(1)&gt;">', false)
        ->assertSee('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; (@octocat) on /dev/null', false);
});

it('mentions the approved parent of a reply in the link preview', function (): void {
    $parent = Commit::factory()->for(User::factory()->state(['username' => 'taylorotwell']))->create(['body' => 'The parent commit']);
    $reply = Commit::factory()->for($parent, 'parent')->for(User::factory()->state(['username' => 'octocat', 'name' => 'octocat']))->create();

    $this->get(route('commit.show', [$reply->user, $reply]))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="@octocat replying to @taylorotwell on /dev/null: “', false)
        ->assertSee('"isPartOf":"'.route('commit.show', [$parent->user, $parent]).'"', false)
        ->assertInertia(fn ($page) => $page->where('ancestors.0.body', 'The parent commit'));
});

it('does not mention an unavailable parent in the link preview', function (Closure $state): void {
    $parent = Commit::factory()->for(User::factory()->state(['username' => 'taylorotwell']))->create(['body' => 'The parent commit']);
    $state($parent);
    $reply = Commit::factory()->for($parent, 'parent')->for(User::factory()->state(['username' => 'octocat', 'name' => 'octocat']))->create();

    $this->get(route('commit.show', [$reply->user, $reply]))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="@octocat on /dev/null: “', false)
        ->assertDontSee('replying to')
        ->assertDontSee('taylorotwell')
        ->assertDontSee('The parent commit');
})->with([
    'pending' => [fn (Commit $parent): bool => $parent->update(['status' => CommitStatus::Pending])],
    'rejected' => [fn (Commit $parent): bool => $parent->update(['status' => CommitStatus::Rejected])],
    'deleted' => [fn (Commit $parent): ?bool => $parent->delete()],
]);

it('escapes the commit in the structured data', function (): void {
    $commit = Commit::factory()->create(['body' => '</script><script>alert(1)</script>']);

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertDontSee('</script><script>alert(1)', false)
        ->assertSee('"text":"\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', false);
});

it('previews the images of a commit before its drawn card', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat']);
    $commit = Commit::factory()->for($user)->create(['body' => 'The new terminal theme']);
    $first = CommitImage::factory()->for($commit)->create(['width' => 1600, 'height' => 900, 'position' => 0]);
    $second = CommitImage::factory()->for($commit)->create(['position' => 1]);

    $content = (string) $this->get(route('commit.show', [$user, $commit]))->assertOk()->getContent();

    expect($content)
        ->toContain('<meta property="og:image" content="'.$first->url().'">
                <meta property="og:image:type" content="image/webp">
                <meta property="og:image:width" content="1600">
                <meta property="og:image:height" content="900">
                <meta property="og:image:alt" content="Image 1 of 2 attached to the commit of The Octocat (@octocat)">')
        ->toContain('<meta name="twitter:image" content="'.$first->url().'">')
        ->toContain('"image":["'.$first->url().'","'.$second->url().'","'.route('commit-open-graph-image.show', [$user, $commit]).'?v=')
        ->and(mb_strpos($content, $second->url()))->toBeLessThan(mb_strpos($content, route('commit-open-graph-image.show', [$user, $commit])));
});

it('uses the attached gif as an open graph image', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'The Octocat']);
    $commit = Commit::factory()->for($user)->withGiphy()->create(['body' => 'Deal with it']);

    $content = (string) $this->get(route('commit.show', [$user, $commit]))->assertOk()->getContent();

    expect($content)
        ->toContain('<meta property="og:image" content="https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123">
                <meta property="og:image:type" content="image/gif">
                <meta property="og:image:width" content="480">
                <meta property="og:image:height" content="270">
                <meta property="og:image:alt" content="Deal With It GIF">')
        ->toContain('<meta name="twitter:image" content="https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123">')
        ->toContain('"image":["https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123","'.route('commit-open-graph-image.show', [$user, $commit]).'?v=')
        ->and(mb_strpos($content, 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123'))->toBeLessThan(mb_strpos($content, route('commit-open-graph-image.show', [$user, $commit])));
});

it('does not use a gif as an open graph image without an attached gif', function (): void {
    $commit = Commit::factory()->create();

    $content = (string) $this->get(route('commit.show', [$commit->user, $commit]))->assertOk()->getContent();

    expect($content)->not->toContain('image/gif');
});

it('previews the drawn card of a commit without images', function (): void {
    $commit = Commit::factory()->create();

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertSee('<meta name="twitter:image" content="'.route('commit-open-graph-image.show', [$commit->user, $commit]).'?v=', false)
        ->assertSee('<meta property="og:image:type" content="image/png">', false)
        ->assertSee('<meta property="og:image:width" content="1200">', false)
        ->assertSee('<meta property="og:image:height" content="630">', false);
});

it('trims long commits in the link preview title', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'name' => 'octocat']);
    $commit = Commit::factory()->for($user)->create(['body' => str_repeat('Writing PHP on purpose. ', 4).'The end is not in the title.']);

    $title = Str::betweenFirst((string) $this->get(route('commit.show', [$user, $commit]))->getContent(), '<meta property="og:title" content="', '"');

    expect($title)->toStartWith('@octocat on /dev/null: “Writing PHP on purpose.')
        ->toEndWith('…”')
        ->not->toContain('The end');
});

it('lets search engines show large image previews of a commit', function (): void {
    $commit = Commit::factory()->create();

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
        ->assertSee('<link rel="alternate" type="application/atom+xml" title="'.e($commit->user->nameWithHandle()).' on /dev/null" href="'.route('user-feed.show', $commit->user).'">', false);
});

it('lists the approved replies of a commit as comments in the structured data', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->for(User::factory()->state(['username' => 'taylorotwell', 'name' => 'Taylor Otwell']))->create(['body' => 'A reply everyone may read']);
    Commit::factory()->for($commit, 'parent')->pending()->create(['body' => 'A pending reply only its author may read']);

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertSee('"comment":[{"@type":"Comment","url":"'.route('commit.show', [$reply->user, $reply]).'","text":"A reply everyone may read","datePublished":"'.$reply->created_at->toIso8601String().'","author":{"@type":"Person","name":"Taylor Otwell","alternateName":"@taylorotwell","url":"'.route('user.show', $reply->user).'"}}]', false)
        ->assertDontSee('A pending reply only its author may read');
});

it('shares the link of a commit in the structured data', function (): void {
    $commit = Commit::factory()->for(LinkPreview::factory()->fetched()->state(['url' => 'https://laravel.com', 'title' => 'Laravel']))->create();

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertSee('"sharedContent":{"@type":"WebPage","url":"https://laravel.com","name":"Laravel"', false);
});

it('shares the quoted commit in the structured data only while it is approved', function (bool $isApproved): void {
    $quoted = Commit::factory()->create(['status' => $isApproved ? CommitStatus::Approved : CommitStatus::Pending]);
    $commit = Commit::factory()->create(['quoted_commit_id' => $quoted->id]);

    $response = $this->get(route('commit.show', [$commit->user, $commit]))->assertOk();

    $isApproved
        ? $response->assertSee('"sharedContent":{"@type":"SocialMediaPosting","url":"'.route('commit.show', [$quoted->user, $quoted]).'"', false)
        : $response->assertDontSee('sharedContent', false);
})->with([
    'approved' => [true],
    'pending' => [false],
]);

it('returns not found to guests for deleted commits', function (): void {
    $commit = Commit::factory()->create();
    $commit->delete();

    $this->get(route('commit.show', [$commit->user, $commit]))->assertNotFound();
});

it('changes the link preview image version only when the public commit changes', function (): void {
    $commit = Commit::factory()->create();

    $version = fn (): string => Str::betweenFirst((string) $this->get(route('commit.show', [$commit->user, $commit]))->getContent(), 'og.png?v=', '"');

    $initial = $version();

    Commit::factory()->for($commit, 'parent')->pending()->create();
    Commit::factory()->for($commit, 'parent')->rejected()->create();

    expect($version())->toBe($initial);

    Commit::factory()->for($commit, 'parent')->create();

    expect($version())->not->toBe($initial);
});

it('returns not found to guests for commits that are not approved', function (Closure $factory): void {
    $commit = $factory();

    $this->get(route('commit.show', [$commit->user, $commit]))->assertNotFound();

    expect(session('url.intended'))->toBeNull();
})->with([
    'pending' => [fn (): Commit => Commit::factory()->pending()->create()],
    'rejected' => [fn (): Commit => Commit::factory()->rejected()->create()],
]);

it('returns not found to guests for a commit under another username', function (): void {
    $commit = Commit::factory()->create();

    $this->get(route('commit.show', [User::factory()->create(), $commit]))->assertNotFound();
});

it('returns not found to guests for unknown commits', function (): void {
    $user = User::factory()->create();

    $this->get("/@{$user->username}/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3")->assertNotFound();
    $this->get('/@missing/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3')->assertNotFound();
});

it('keeps the default link preview for signed-in users on a commit', function (): void {
    $commit = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('commit/show'))
        ->assertSee('<meta property="og:image" content="'.url('/og.png').'">', false)
        ->assertDontSee('og:url', false);
});

it('returns to the intended commit after local sign in', function (): void {
    app()->instance('env', 'local');
    config()->set('app.auto_login', true);

    $commit = Commit::factory()->create();

    $this->get(route('commit.show', [$commit->user, $commit]))->assertRedirectToRoute('home');

    $this->get(route('home'))->assertRedirect(route('commit.show', [$commit->user, $commit]));
});

it('queues a link preview for a commit with a link', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Read all about it at https://example.com/link-previews.',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following);

    expect($user->commits()->sole()->linkPreview->url)->toBe('https://example.com/link-previews');

    Queue::assertPushed(UnfurlLink::class);
});

it('keeps attached images with the link card the author chose', function (): void {
    Queue::fake();
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Shipped https://example.com/first, docs at laravel.com',
            'link_preview_url' => 'https://laravel.com',
            'images' => [UploadedFile::fake()->image('draft.png', 10, 10)],
            'image_descriptions' => ['The shipped feature.'],
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following);

    $commit = $user->commits()->sole();

    expect($commit->linkPreview->url)->toBe('https://laravel.com')
        ->and($commit->images->sole()->description)->toBe('The shipped feature.')
        ->and($commit->images)->toHaveCount(1)
        ->and(Storage::disk()->allFiles('commit-images'))->toHaveCount(1);

    Queue::assertPushed(UnfurlLink::class);
});

it('attaches no link preview when the author chose none', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Shipped https://example.com/first',
            'link_preview_url' => '',
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following);

    expect($user->commits()->sole()->link_preview_id)->toBeNull();

    Queue::assertNothingPushed();
});

it('may not choose a link preview for a url that is not in the body', function (string $url): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Shipped https://example.com/first and `https://example.com/code`',
            'link_preview_url' => $url,
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasErrors(['link_preview_url' => 'The link card must be one of the links in the body.']);

    expect(Commit::query()->count())->toBe(0);
})->with([
    'another url' => 'https://evil.example.com',
    'a url in code' => 'https://example.com/code',
    'part of a url' => 'https://example.com',
]);

it('includes the link previews of a commit, its ancestors and its replies', function (): void {
    $root = Commit::factory()->for(LinkPreview::factory()->fetched()->state(['title' => 'Root link']))->create();
    $commit = Commit::factory()->for($root, 'parent')->for(LinkPreview::factory()->fetched()->state(['title' => 'Focused link']))->create();
    Commit::factory()->for($commit, 'parent')->for(LinkPreview::factory()->fetched()->state(['title' => 'Reply link']))->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.link_preview.title', 'Focused link')
            ->where('ancestors.0.link_preview.title', 'Root link')
            ->where('replies.data.0.link_preview.title', 'Reply link'));
});

it('may attach images to a commit', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Look at this',
            'images' => [
                UploadedFile::fake()->image('first.jpg', 40, 30),
                UploadedFile::fake()->image('second.png', 10, 10),
                UploadedFile::fake()->image('third.webp', 10, 10),
            ],
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasNoErrors();

    $commit = $user->commits()->sole();

    expect($commit->status)->toBe(CommitStatus::Pending)
        ->and($commit->images)->toHaveCount(3)
        ->and(Storage::disk()->allFiles('commit-images'))->toHaveCount(3);
});

it('may commit images without a body', function (array $body): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            ...$body,
            'images' => [UploadedFile::fake()->image('screenshot.png', 40, 30)],
        ]);

    $response->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasNoErrors();

    $commit = $user->commits()->sole();

    expect($commit->body)->toBe('')
        ->and($commit->images)->toHaveCount(1);
})->with([
    'missing' => [[]],
    'blank' => [['body' => '   ']],
]);

it('may commit images with a body shorter than 3 characters', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('commit.store'), [
            'body' => 'ok',
            'images' => [UploadedFile::fake()->image('screenshot.png', 40, 30)],
        ])
        ->assertSessionHasNoErrors();

    expect($user->commits()->sole()->body)->toBe('ok');
});

it('may quote a commit with only images', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $quoted = Commit::factory()->create();

    $this->actingAs($user)
        ->post(route('commit.store'), [
            'quoted_commit_id' => $quoted->id,
            'images' => [UploadedFile::fake()->image('screenshot.png', 40, 30)],
        ])
        ->assertSessionHasNoErrors();

    expect($user->commits()->sole())
        ->body->toBe('')
        ->quoted_commit_id->toBe($quoted->id);
});

it('may describe the images attached to a commit', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Offsite photo dump',
        'images' => [
            UploadedFile::fake()->image('cabin.jpg', 40, 30),
            UploadedFile::fake()->image('hike.jpg', 40, 30),
        ],
        'image_descriptions' => ['The cabin at sunrise, fog over the lake.', ''],
    ]);

    $response->assertSessionHasNoErrors();

    expect($user->commits()->sole()->images->pluck('description')->all())->toBe([
        'The cabin at sunrise, fog over the lake.',
        null,
    ]);
});

it('may not describe an image in more than a thousand characters', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Too long a description',
        'images' => [UploadedFile::fake()->image('photo.jpg', 10, 10)],
        'image_descriptions' => [str_repeat('a', 1001)],
    ]);

    $response->assertSessionHasErrors('image_descriptions.0');

    expect($user->commits()->count())->toBe(0);
});

it('may attach images to a quote with the chosen link card', function (?string $linkPreviewUrl, ?string $expectedUrl): void {
    Storage::fake();
    Queue::fake([UnfurlLink::class]);

    $user = User::factory()->create();
    $quoted = Commit::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Compare https://example.com/first and https://example.com/second',
            'quoted_commit_id' => $quoted->id,
            'images' => [UploadedFile::fake()->image('trace.png', 40, 30)],
            'image_descriptions' => ['Trace showing the regression.'],
            ...($linkPreviewUrl === null ? [] : ['link_preview_url' => $linkPreviewUrl]),
        ])
        ->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasNoErrors();

    $commit = $user->commits()->sole();

    expect($commit->quoted_commit_id)->toBe($quoted->id)
        ->and($commit->images)->toHaveCount(1)
        ->and($commit->images->sole()->description)->toBe('Trace showing the regression.')
        ->and($commit->linkPreview?->url)->toBe($expectedUrl);

    if ($expectedUrl === null) {
        Queue::assertNotPushed(UnfurlLink::class);
    } else {
        Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
    }
})->with([
    'default card' => [null, 'https://example.com/first'],
    'chosen card' => ['https://example.com/second', 'https://example.com/second'],
    'no card' => ['', null],
]);

it('may not attach more than three images to a commit', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Too many',
        'images' => array_map(fn (int $index): UploadedFile => UploadedFile::fake()->image("{$index}.png", 10, 10), range(1, 4)),
    ]);

    $response->assertSessionHasErrors('images');

    expect($user->commits()->count())->toBe(0);
});

it('may only attach jpeg, png or webp images to a commit', function (UploadedFile $file): void {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Not an image',
        'images' => [$file],
    ]);

    $response->assertSessionHasErrors('images.0');

    expect($user->commits()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
})->with([
    'svg' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'html disguised as png' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('image.png', '<html><script>alert(1)</script></html>'),
    'bmp' => fn (): UploadedFile => UploadedFile::fake()->image('image.bmp', 10, 10),
    'gif' => fn (): UploadedFile => UploadedFile::fake()->image('image.gif', 10, 10),
    'pdf' => fn (): UploadedFile => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
]);

it('may not attach images larger than ten megabytes to a commit', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Too big',
        'images' => [UploadedFile::fake()->image('huge.jpg', 10, 10)->size(10241)],
    ]);

    $response->assertSessionHasErrors('images.0');
});

it('may not attach images with too many pixels to a commit', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Too wide',
        'images' => [UploadedFile::fake()->image('wide.png', 6001, 1)],
    ]);

    $response->assertSessionHasErrors('images.0');
});

it('includes the images of a commit, its ancestors and its replies', function (): void {
    $root = Commit::factory()->create();
    $commit = Commit::factory()->for($root, 'parent')->create();
    Commit::factory()->for($commit, 'parent')->create();

    Commit::query()->each(fn (Commit $commit): CommitImage => CommitImage::factory()->for($commit)->create());

    $response = $this->actingAs(User::factory()->create())
        ->get(route('commit.show', [$commit->user, $commit]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.images.0.url', route('commit-image.show', $commit->images()->sole()))
            ->has('ancestors.0.images', 1)
            ->has('replies.data.0.images', 1)
            ->missing('commit.images.0.path'));
});

it('may attach a giphy gif to a commit', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Shipping it',
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/v1.Y2lkPTc5MGI3NjEx/giphy-downsized-medium.gif?cid=abc123&rid=giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_title' => 'Deal With It GIF',
        'giphy_username' => 'giphy',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ]);

    $response->assertSessionHasNoErrors();

    $commit = $user->commits()->sole();
    expect($commit->giphy_id)->toBe('xT9IgzoKnwFNmISR8I')
        ->and($commit->giphy_url)->toBe('https://media2.giphy.com/media/v1.Y2lkPTc5MGI3NjEx/giphy-downsized-medium.gif?cid=abc123&rid=giphy.gif')
        ->and($commit->giphy_width)->toBe(480);
});

it('rejects a gif media url that is not on giphy', function (string $url): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Nice try',
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => $url,
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasErrors('giphy_url');

    expect($user->commits()->count())->toBe(0);
})->with([
    'another host' => ['https://evil.example/media/xT9IgzoKnwFNmISR8I/giphy.gif'],
    'lookalike host' => ['https://media2.giphy.com.evil.example/media/x/giphy.gif'],
    'plain http' => ['http://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif'],
    'not a gif rendition' => ['https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.webp'],
    'outside the media path' => ['https://media2.giphy.com/avatars/giphy.gif'],
    'with a fragment' => ['https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif#frag'],
    'with userinfo' => ['https://user@media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif'],
    'with a port' => ['https://media2.giphy.com:8443/media/xT9IgzoKnwFNmISR8I/giphy.gif'],
    'dot-segment traversal' => ['https://media2.giphy.com/media/../avatars/evil.gif'],
    'single-dot segment' => ['https://media2.giphy.com/media/./xT9IgzoKnwFNmISR8I/giphy.gif'],
]);

it('rejects a partial gif submission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Half a gif',
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
    ])->assertSessionHasErrors(['giphy_url', 'giphy_page_url', 'giphy_width', 'giphy_height']);
});

it('rejects a gif alongside uploaded images', function (): void {
    Storage::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Both at once',
        'images' => [UploadedFile::fake()->image('cabin.jpg', 40, 30)],
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasErrors('giphy_id');
});

it('rejects a gif alongside a poll', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Gif or poll',
        'poll' => ['options' => ['Yes', 'No'], 'duration' => 1440],
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasErrors('giphy_id');
});

it('may quote a commit with a gif attached', function (): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Quoting with style',
        'quoted_commit_id' => $quoted->id,
        'giphy_id' => 'xT9IgzoKnwFNmISR8I',
        'giphy_url' => 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy.gif',
        'giphy_page_url' => 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        'giphy_width' => 480,
        'giphy_height' => 270,
    ])->assertSessionHasNoErrors();

    expect($user->commits()->sole()->quoted_commit_id)->toBe($quoted->id);
});

it('shows the gif in the commit payload', function (): void {
    $commit = Commit::factory()->withGiphy()->create();

    $this->actingAs($commit->user)
        ->get(route('commit.show', [$commit->user, $commit]))
        ->assertInertia(fn ($page) => $page
            ->where('commit.gif.id', 'xT9IgzoKnwFNmISR8I')
            ->where('commit.gif.url', 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123')
            ->missing('commit.giphy_url'));
});
