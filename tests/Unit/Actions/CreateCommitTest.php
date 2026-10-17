<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Dto\NewPoll;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Enums\PollDuration;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\Notification;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('may create a commit', function (): void {
    $user = User::factory()->create();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle($user, 'Hello, devnull!');

    expect($commit->exists)->toBeTrue()
        ->and($commit->body)->toBe('Hello, devnull!')
        ->and($commit->user->is($user))->toBeTrue();
});

it('creates commits pending review', function (): void {
    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Initial commit');

    expect($commit->status)->toBe(CommitStatus::Pending);
});

it('scores pending commits for moderation triage', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Join my discord https://example.com');

    expect($commit->refresh())
        ->status->toBe(CommitStatus::Pending)
        ->body_hash->not->toBeNull()
        ->moderation_score->toBeGreaterThan(0)
        ->and(array_column($commit->moderation_signals ?? [], 'code'))
        ->toContain('invite_like')
        ->toContain('has_url');
});

it('does not score admin commits that are approved immediately', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Join my discord');

    expect($commit->refresh())
        ->status->toBe(CommitStatus::Approved)
        ->moderation_score->toBe(0)
        ->moderation_signals->toBeNull()
        ->body_hash->toBeNull();
});

it('stores emoji shortcodes as their emoji', function (): void {
    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'shipped it :heart :like :rocket:');

    expect($commit->body)->toBe('shipped it ❤️ 👍 🚀');
});

it('approves commits of admins right away', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Initial commit');

    expect($commit->status)->toBe(CommitStatus::Approved);
});

it('notifies the parent author about replies of admins', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $parent = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $reply = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Nice one', $parent);

    expect($parent->user->notifications()->sole()->commit_id)->toBe($reply->id);
});

it('does not notify about pending replies', function (): void {
    $parent = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(), 'Nice one', $parent);

    expect(Notification::query()->count())->toBe(0);
});

it('may quote a commit', function (): void {
    $quoted = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $quote = $action->handle(User::factory()->create(), 'Worth a read', quotedCommit: $quoted);

    expect($quote->quotedCommit->is($quoted))->toBeTrue()
        ->and($quote->parent_id)->toBeNull();
});

it('notifies the quoted author about quotes of admins', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $quoted = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $quote = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Worth a read', quotedCommit: $quoted);

    expect($quoted->user->notifications()->sole())
        ->type->toBe(NotificationType::Quoted)
        ->commit_id->toBe($quote->id);
});

it('does not notify about pending quotes', function (): void {
    $quoted = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(), 'Worth a read', quotedCommit: $quoted);

    expect(Notification::query()->count())->toBe(0);
});

it('notifies mentioned users about commits of admins', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $mentioned = User::factory()->create(['username' => 'taylorotwell']);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Shipped it with @taylorotwell');

    expect($mentioned->notifications()->sole()->commit_id)->toBe($commit->id);
});

it('does not notify about pending mentions', function (): void {
    User::factory()->create(['username' => 'taylorotwell']);

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(), 'Shipped it with @taylorotwell');

    expect(Notification::query()->count())->toBe(0);
});

it('asks the ai for its decision on pending commits', function (): void {
    config(['services.openai.key' => 'sk-test']);
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Initial commit');

    Queue::assertPushed(ModerateCommit::class, fn (ModerateCommit $job): bool => $job->commit->is($commit));
});

it('does not ask the ai about commits of admins', function (): void {
    config(['services.openai.key' => 'sk-test', 'app.admins' => ['nunomaduro']]);
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Initial commit');

    Queue::assertNotPushed(ModerateCommit::class);
});

it('queues ai review for a quote while preserving its images and chosen link card', function (): void {
    config(['services.openai.key' => 'sk-test']);
    Storage::fake();
    Queue::fake([ModerateCommit::class, UnfurlLink::class, EmbedCommit::class]);

    $quoted = Commit::factory()->create();

    $quote = resolve(CreateCommit::class)->handle(
        User::factory()->create(),
        'Compare https://example.com/first and https://example.com/second',
        quotedCommit: $quoted,
        images: [UploadedFile::fake()->image('comparison.png', 10, 10)],
        linkPreview: 'https://example.com/second',
    );

    expect($quote->status)->toBe(CommitStatus::Pending)
        ->and($quote->quoted_commit_id)->toBe($quoted->id)
        ->and($quote->images)->toHaveCount(1)
        ->and($quote->linkPreview->url)->toBe('https://example.com/second')
        ->and($quoted->user->notifications()->exists())->toBeFalse();

    Queue::assertPushed(ModerateCommit::class, fn (ModerateCommit $job): bool => $job->commit->is($quote));
    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($quote->linkPreview));
});

it('does not ask the ai without an api key', function (): void {
    config(['services.openai.key' => null]);
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(), 'Initial commit');

    Queue::assertNotPushed(ModerateCommit::class);
});

it('attaches a link preview for the first url in the body', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), "Shipped https://example.com/first.\nAlso https://example.com/second");

    expect($commit->refresh()->linkPreview)
        ->url->toBe('https://example.com/first')
        ->url_hash->toBe(hash('sha256', 'https://example.com/first'))
        ->fetched_at->toBeNull();

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
});

it('attaches a link preview for the chosen url in the body', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Shipped https://example.com/first and https://example.com/second', linkPreview: 'https://example.com/second');

    expect($commit->refresh()->linkPreview->url)->toBe('https://example.com/second');

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
});

it('attaches no link preview when the author chose none', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Shipped https://example.com/first', linkPreview: false);

    expect($commit->refresh()->link_preview_id)->toBeNull()
        ->and(LinkPreview::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('attaches a link preview for a bare domain', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Shipped nativephp.com');

    expect($commit->refresh()->linkPreview)
        ->url->toBe('https://nativephp.com')
        ->url_hash->toBe(hash('sha256', 'https://nativephp.com'));

    Queue::assertPushed(UnfurlLink::class);
});

it('attaches a link preview to replies', function (): void {
    Queue::fake();

    $parent = Commit::factory()->create();

    $action = resolve(CreateCommit::class);

    $reply = $action->handle(User::factory()->create(), 'Docs are at https://example.com/docs', $parent);

    expect($reply->refresh())
        ->parent_id->toBe($parent->id)
        ->linkPreview->url->toBe('https://example.com/docs');

    Queue::assertPushed(UnfurlLink::class);
});

it('reuses the link preview of a url that was linked before', function (): void {
    Queue::fake();

    $linkPreview = LinkPreview::factory()->create(['url' => 'https://example.com']);

    $action = resolve(CreateCommit::class);

    $first = $action->handle(User::factory()->create(), 'Look at https://example.com');
    $second = $action->handle(User::factory()->create(), 'Also https://example.com!');

    expect(LinkPreview::query()->count())->toBe(1)
        ->and($first->link_preview_id)->toBe($linkPreview->id)
        ->and($second->link_preview_id)->toBe($linkPreview->id);
});

it('only fetches link previews that were never fetched', function (): void {
    Queue::fake();

    LinkPreview::factory()->fetched()->create(['url' => 'https://example.com']);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Look at https://example.com');

    expect($commit->linkPreview)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('queues the link preview for the saved commit', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Look at https://example.com');

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));

    expect($commit->exists)->toBeTrue();
});

it('attaches no link preview without a url', function (): void {
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Shipped Node.js 24, see README.md');

    expect($commit->refresh()->link_preview_id)->toBeNull()
        ->and(LinkPreview::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('attaches a poll with its options in order', function (): void {
    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Tabs or spaces?', poll: new NewPoll(['Tabs', 'Spaces', 'Both :fire'], PollDuration::ThreeDays));

    expect($commit->poll)
        ->duration->toBe(PollDuration::ThreeDays)
        ->opened_at->toBeNull()
        ->and($commit->poll->options->pluck('label')->all())->toBe(['Tabs', 'Spaces', 'Both 🔥']);
});

it('opens the poll of an admin right away', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Tabs or spaces?', poll: new NewPoll(['Tabs', 'Spaces'], PollDuration::OneHour));

    expect($commit->poll->closesAt()?->toIso8601String())->toBe(now()->addHour()->toIso8601String());
});

it('stores the images of a commit', function (): void {
    Storage::fake();

    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Look at this', images: [
        UploadedFile::fake()->image('photo.jpg', 10, 10),
    ]);

    expect($commit->images()->count())->toBe(1)
        ->and($commit->status)->toBe(CommitStatus::Pending);
});

it('files replies under the top-level commit of their thread', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();

    $nested = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Agreed', $reply);

    expect($nested->root_id)->toBe($commit->id);
});

it('lifts the thread when an admin replies', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $commit = Commit::factory()->create();

    resolve(CreateCommit::class)->handle(User::factory()->create(['username' => 'nunomaduro']), 'Nice one', $commit);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 1), 1e-9);
});

it('lifts the quoted thread when an admin quotes', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $commit = Commit::factory()->create();

    resolve(CreateCommit::class)->handle(User::factory()->create(['username' => 'nunomaduro']), 'Worth a read', quotedCommit: $commit);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 1), 1e-9);
});

it('keeps a poll on a quote while only approved quotes lift its thread', function (bool $admin): void {
    config(['app.admins' => ['admin']]);

    $root = Commit::factory()->create();
    $reply = Commit::factory()->for($root, 'parent')->create();
    $user = User::factory()->create(['username' => $admin ? 'admin' : 'author']);

    $quote = resolve(CreateCommit::class)->handle(
        $user,
        'Which approach?',
        quotedCommit: $reply,
        poll: new NewPoll(['Tabs', 'Spaces'], PollDuration::OneDay),
    );

    expect($quote->quoted_commit_id)->toBe($reply->id)
        ->and($quote->status)->toBe($admin ? CommitStatus::Approved : CommitStatus::Pending)
        ->and($quote->poll->options->pluck('label')->all())->toBe(['Tabs', 'Spaces'])
        ->and($quote->poll->opened_at?->toIso8601String())->toBe($admin ? now()->toIso8601String() : null)
        ->and($root->fresh()->hot_score)->toEqualWithDelta(
            $admin ? resolve(TheAlgorithm::class)->score($root->created_at, 0, 2) : $root->hot_score,
            1e-9,
        );
})->with([false, true]);
