<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

test('to array', function (): void {
    $commit = Commit::factory()->hasAttached(Topic::factory())->create(['topic_decision' => true])->refresh()->load('topics');

    expect(array_keys($commit->toArray()))
        ->toBe([
            'id',
            'user_id',
            'body',
            'created_at',
            'updated_at',
            'status',
            'parent_id',
            'deleted_at',
            'link_preview_id',
            'quoted_commit_id',
            'mentions',
            'root_id',
            'hot_score',
            'pasteis_count',
            'body_segments',
            'preview_segments',
            'is_checking',
        ]);
});

it('splits its body into segments', function (): void {
    $commit = Commit::factory()->create(['body' => 'run `make`']);

    expect($commit->toArray()['body_segments'])->toBe([
        ['type' => 'text', 'text' => 'run '],
        ['type' => 'code', 'code' => 'make'],
    ]);
});

it('splits the excerpt of a body too long to show whole in a list into segments too', function (): void {
    $short = Commit::factory()->create(['body' => 'run `make`']);
    $long = Commit::factory()->create(['body' => 'run `make` '.str_repeat('again ', 60)]);

    expect($short->toArray()['preview_segments'])->toBeNull()
        ->and($long->toArray()['preview_segments'])->toBe([
            ['type' => 'text', 'text' => 'run '],
            ['type' => 'code', 'code' => 'make'],
            ['type' => 'text', 'text' => ' '.mb_rtrim(str_repeat('again ', 45)).'…'],
        ]);
});

it('recomputes segments when the body changes', function (): void {
    $commit = Commit::factory()->create(['body' => 'first version']);

    expect($commit->toArray()['body_segments'])->toBe([
        ['type' => 'text', 'text' => 'first version'],
    ]);

    $commit->body = 'second `version`';

    expect($commit->toArray()['body_segments'])->toBe([
        ['type' => 'text', 'text' => 'second '],
        ['type' => 'code', 'code' => 'version'],
    ]);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    expect($commit->user->is($user))->toBeTrue();
});

it('casts the status to an enum', function (): void {
    $commit = Commit::factory()->pending()->create()->refresh();

    expect($commit->status)->toBe(CommitStatus::Pending);
});

it('is visible when approved or owned', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $approved = Commit::factory()->for($author)->create();
    $pending = Commit::factory()->for($author)->pending()->create();
    $rejected = Commit::factory()->for($author)->rejected()->create();

    expect($approved->isVisibleTo($viewer))->toBeTrue()
        ->and($pending->isVisibleTo($author))->toBeTrue()
        ->and($pending->isVisibleTo($viewer))->toBeFalse()
        ->and($rejected->isVisibleTo($author))->toBeTrue()
        ->and($rejected->isVisibleTo($viewer))->toBeFalse();
});

it('deletes stars with the commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();

    $user->starredCommits()->attach($commit);

    $commit->delete();

    expect($user->starredCommits()->count())->toBe(0);
});

it('checks account deletion tombstone visibility without an author', function (CommitStatus $status, bool $authenticated): void {
    $author = User::factory()->create();
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($author)->for($root, 'parent')->create(['status' => $status]);
    Commit::factory()->for($parent, 'parent')->create();
    resolve(DeleteUser::class)->handle($author);
    $tombstone = Commit::withTrashed()->findOrFail($parent->id);

    expect($tombstone->isVisibleTo($authenticated ? User::factory()->create() : null))
        ->toBe($status === CommitStatus::Approved);
})->with([
    'approved' => [CommitStatus::Approved],
    'pending' => [CommitStatus::Pending],
    'rejected' => [CommitStatus::Rejected],
])->with([true, false]);

it('may reply to another commit', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    expect($reply->parent->is($parent))->toBeTrue()
        ->and($parent->replies->sole()->is($reply))->toBeTrue();
});

it('keeps replies when their parent is deleted', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $parent->delete();

    $this->assertSoftDeleted($parent);
    $this->assertNotSoftDeleted($reply);
});

it('erases replies along with their parent when force deleted', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $nested = Commit::factory()->for($reply, 'parent')->create();

    $parent->forceDelete();

    expect(Commit::withTrashed()->whereKey([$reply->id, $nested->id])->exists())->toBeFalse();
});

it('may quote another commit', function (): void {
    $quoted = Commit::factory()->create();
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();

    expect($quote->quotedCommit->is($quoted))->toBeTrue()
        ->and($quoted->quotes->sole()->is($quote))->toBeTrue()
        ->and($quoted->quotedCommit)->toBeNull();
});

it('keeps quotes when the quoted commit is deleted', function (): void {
    $quoted = Commit::factory()->create();
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();

    $quoted->delete();

    $this->assertNotSoftDeleted($quote);

    expect($quote->refresh()->quoted_commit_id)->toBe($quoted->id);
});

it('keeps quotes without the reference when the quoted commit is force deleted', function (): void {
    $quoted = Commit::factory()->create();
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create();

    $quoted->forceDelete();

    $this->assertNotSoftDeleted($quote);

    expect($quote->refresh()->quoted_commit_id)->toBeNull();
});

it('leaves the quoted commit out of a tombstone', function (): void {
    $commit = Commit::factory()->for(Commit::factory(), 'quotedCommit')->create();
    $commit->delete();

    $tombstone = Commit::withTrashed()->with('quotedCommit')->findOrFail($commit->id);

    expect($tombstone->toArray())->not->toHaveKeys(['quoted_commit_id', 'quoted_commit']);
});

it('serializes a deleted commit as a tombstone without its author or body', function (): void {
    $parent = Commit::factory()->create();
    $commit = Commit::factory()->for($parent, 'parent')->create();
    $commit->delete();

    $tombstone = Commit::withTrashed()->withCount('replies')->with('user')->findOrFail($commit->id);

    expect($tombstone->toArray())->toBe([
        'id' => $commit->id,
        'parent_id' => $parent->id,
        'deleted_at' => $tombstone->deleted_at?->toJSON(),
        'replies_count' => 0,
    ]);
});

it('counts visible replies and quotes in public previews', function (): void {
    $parts = [Commit::factory()->create()];
    Commit::factory()->for($parts[0], 'parent')->create();
    Commit::factory()->for($parts[0], 'parent')->pending()->create();
    Commit::factory()->for($parts[0], 'quotedCommit')->create();
    Commit::factory()->for($parts[0], 'quotedCommit')->pending()->create();
    Commit::factory()->for($parts[0], 'quotedCommit')->rejected()->create();
    Commit::factory()->for($parts[0], 'quotedCommit')->create()->delete();

    $parts[0]->fill(['body' => 'Unsaved preview body']);
    $preview = $parts[0]->loadPublicPreview();

    expect($preview)->toBe($parts[0])
        ->and($preview->replies_count)->toBe(2)
        ->and($preview->toArray()['replies_count'])->toBe(2)
        ->and($preview->isDirty('replies_count'))->toBeFalse()
        ->and($preview->isDirty('body'))->toBeTrue();
});

it('counts only approved quotes in its public preview', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'quotedCommit')->create();
    Commit::factory()->for($commit, 'quotedCommit')->pending()->create();

    $preview = $commit->loadPublicPreview();

    expect($preview->replies_count)->toBe(1)
        ->and($preview->isDirty('replies_count'))->toBeFalse();
});

it('loads a public preview for an unsaved commit without making it persistent', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->make();

    $preview = $commit->loadPublicPreview();

    expect($preview)->toBe($commit)
        ->and($preview->exists)->toBeFalse()
        ->and($preview->replies_count)->toBe(0)
        ->and($preview->isDirty('replies_count'))->toBeFalse()
        ->and($preview->user->is($user))->toBeTrue();
});

it('loads public preview counts onto a partially selected commit with its id', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'quotedCommit')->create();
    $partial = Commit::query()->select(['id', 'user_id', 'parent_id'])->findOrFail($commit->id);

    $partial->loadPublicPreview();

    expect($partial->replies_count)->toBe(2)
        ->and($partial->isDirty('replies_count'))->toBeFalse()
        ->and($partial->getAttributes())->not->toHaveKey('body');
});

it('loads an unsaved public preview without dirtying its computed count', function (): void {
    $commit = Commit::factory()->make();

    $preview = $commit->loadPublicPreview();

    expect($preview->replies_count)->toBe(0)
        ->and($preview->isDirty('replies_count'))->toBeFalse()
        ->and($preview->exists)->toBeFalse();
});

it('counts public replies and quotes in a deleted commit preview without dirtying its count', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'quotedCommit')->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    Commit::factory()->for($commit, 'quotedCommit')->pending()->create();
    $commit->delete();

    $preview = $commit->loadPublicPreview();

    expect($preview->replies_count)->toBe(2)
        ->and($preview->isDirty('replies_count'))->toBeFalse();
});

it('loads a public preview without a persisted primary key', function (bool $partial): void {
    $author = User::factory()->create();
    $commit = $partial
        ? Commit::query()->select(['user_id', 'parent_id'])->findOrFail(Commit::factory()->for($author)->create()->id)
        : Commit::factory()->for($author)->make(['id' => fake()->uuid()]);

    $preview = $commit->loadPublicPreview();

    expect($preview)->toBe($commit)
        ->and($preview->replies_count)->toBe(0)
        ->and($preview->isDirty('replies_count'))->toBeFalse()
        ->and($preview->user?->is($author))->toBeTrue();
})->with([
    'unsaved commit' => false,
    'partial commit without its primary key' => true,
]);

it('hydrates public preview counts without persisting them or clearing body edits', function (bool $deleted): void {
    $parent = Commit::factory()->create();
    $commit = Commit::factory()->for($parent, 'parent')->create()->refresh();
    Commit::factory()->for($commit, 'parent')->create();
    Commit::factory()->for($commit, 'quotedCommit')->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    Commit::factory()->for($commit, 'parent')->rejected()->create();
    $deletedReply = Commit::factory()->for($commit, 'parent')->create();
    $deletedReply->delete();

    $commit->load('images');
    $images = $commit->images;
    $author = $commit->user;

    if ($deleted) {
        $commit->delete();
    }

    $commit->fill(['body' => 'Edited after loading']);

    $preview = $commit->loadPublicPreview();

    expect($preview)->toBe($commit)
        ->and($preview->replies_count)->toBe(2)
        ->and($preview->isDirty('replies_count'))->toBeFalse()
        ->and($preview->getDirty())->toBe(['body' => 'Edited after loading'])
        ->and($preview->images)->toBe($images)
        ->and($preview->user->is($author))->toBeTrue()
        ->and($preview->parent->is($parent))->toBeTrue();

    if ($deleted) {
        expect($preview->toArray())->toBe([
            'id' => $commit->id,
            'parent_id' => $parent->id,
            'deleted_at' => $commit->deleted_at?->toJSON(),
            'replies_count' => 2,
        ]);
    }

    $preview->save();

    expect(Commit::withTrashed()->findOrFail($commit->id)->body)->toBe('Edited after loading')
        ->and($preview->isDirty())->toBeFalse();
})->with([false, true]);

it('hydrates a zero public preview count when no persisted primary key is available', function (Closure $makeCommit): void {
    $commit = $makeCommit();
    $strict = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();

    try {
        $preview = $commit->loadPublicPreview();

        expect($preview)->toBe($commit)
            ->and($preview->replies_count)->toBe(0)
            ->and($preview->isDirty('replies_count'))->toBeFalse()
            ->and($preview->relationLoaded('user'))->toBeTrue()
            ->and($preview->relationLoaded('parent'))->toBeTrue();
    } finally {
        Model::preventAccessingMissingAttributes($strict);
    }
})->with([
    'unsaved' => [fn (): Commit => new Commit],
    'unsaved with uuid' => [fn (): Commit => Commit::factory()->make(['id' => fake()->uuid()])],
    'partial without id' => [function (): Commit {
        Commit::factory()->create();

        return Commit::query()->firstOrFail(['body', 'user_id', 'parent_id']);
    }],
    'removed row' => [function (): Commit {
        $commit = Commit::factory()->create();
        $commit->forceDelete();

        return $commit;
    }],
]);

it('may belong to a link preview', function (): void {
    $linkPreview = LinkPreview::factory()->create();
    $commit = Commit::factory()->for($linkPreview)->create();

    expect($commit->linkPreview->is($linkPreview))->toBeTrue()
        ->and(Commit::factory()->create()->linkPreview)->toBeNull();
});

it('leaves the link preview out of a tombstone', function (): void {
    $commit = Commit::factory()->for(LinkPreview::factory()->fetched())->create();
    $commit->delete();

    $tombstone = Commit::withTrashed()->with('linkPreview')->findOrFail($commit->id);

    expect($tombstone->toArray())->not->toHaveKeys(['link_preview_id', 'link_preview']);
});

it('is not visible across a block in either direction', function (bool $viewerBlocks): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $commit = Commit::factory()->for($author)->create();

    $viewerBlocks ? $viewer->blocking()->attach($author) : $author->blocking()->attach($viewer);

    expect($commit->isVisibleTo($viewer))->toBeFalse()
        ->and($commit->isVisibleTo($author))->toBeTrue();
})->with([true, false]);

it('has images in order', function (): void {
    $commit = Commit::factory()->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0]);

    expect($commit->images->modelKeys())->toBe([$first->id, $second->id]);
});

it('shows images when approved, to the author, or to moderators', function (): void {
    config(['app.admins' => ['admin']]);

    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $moderator = User::factory()->create(['username' => 'admin']);
    $approved = Commit::factory()->for($author)->create();
    $pending = Commit::factory()->for($author)->pending()->create();
    $rejected = Commit::factory()->for($author)->rejected()->create();

    expect($approved->imagesAreVisibleTo(null))->toBeTrue()
        ->and($approved->imagesAreVisibleTo($viewer))->toBeTrue()
        ->and($pending->imagesAreVisibleTo(null))->toBeFalse()
        ->and($pending->imagesAreVisibleTo($viewer))->toBeFalse()
        ->and($pending->imagesAreVisibleTo($author))->toBeTrue()
        ->and($pending->imagesAreVisibleTo($moderator))->toBeTrue()
        ->and($rejected->imagesAreVisibleTo($viewer))->toBeFalse()
        ->and($rejected->imagesAreVisibleTo($moderator))->toBeTrue();

    $approved->delete();

    expect($approved->imagesAreVisibleTo($author))->toBeFalse();
});

it('never shows the images of a deleted commit in its tombstone', function (): void {
    $commit = Commit::factory()->create();
    CommitImage::factory()->for($commit)->create();
    $commit->delete();

    $tombstone = Commit::withTrashed()->with('images')->findOrFail($commit->id);

    expect($tombstone->toArray())->not->toHaveKey('images');
});
