<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Dto\NewPoll;
use App\Dto\ThreadEngagement;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Enums\PollDuration;
use App\Exceptions\CommitProcessingFailed;
use App\Jobs\CheckUserLevel;
use App\Jobs\ClassifyCommitTopics;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\CommitReport;
use App\Models\LinkPreview;
use App\Models\Notification;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
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

it('stores emoji shortcodes as their emoji', function (): void {
    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'shipped it :heart: :like: :rocket and :rocket:');

    expect($commit->body)->toBe('shipped it ❤️ 👍 :rocket and 🚀');
});

it('approves commits of admins right away', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Initial commit');

    expect($commit->status)->toBe(CommitStatus::Approved);
});

it('skips ai review only when all reputation requirements are met', function (int $ageInSeconds, int $followers, ?CommitStatus $previousStatus, bool $deleted, bool $approved): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();

    $user = User::factory()->create(['created_at' => now()->subSeconds($ageInSeconds)]);
    followersOf($user, $followers);

    if ($previousStatus instanceof CommitStatus) {
        $previous = Commit::factory()->for($user)->create(['status' => $previousStatus]);

        if ($deleted) {
            $previous->delete();
        }
    }

    $commit = resolve(CreateCommit::class)->handle($user, 'A new commit', poll: new NewPoll(['Yes', 'No'], PollDuration::OneHour));

    expect($commit->refresh())
        ->status->toBe($approved ? CommitStatus::Approved : CommitStatus::Pending)
        ->ai_decision->toBeNull()
        ->and($commit->poll->opened_at?->toIso8601String())->toBe($approved ? now()->toIso8601String() : null);

    if ($approved) {
        Queue::assertNotPushed(ModerateCommit::class);
    } else {
        Queue::assertPushed(ModerateCommit::class);
    }
})->with([
    'all requirements met' => [3601, 10, CommitStatus::Approved, false, true],
    'more followers' => [7200, 11, CommitStatus::Approved, false, true],
    'exactly one hour old' => [3600, 10, CommitStatus::Approved, false, false],
    'less than one hour old' => [3599, 10, CommitStatus::Approved, false, false],
    'only nine followers' => [3601, 9, CommitStatus::Approved, false, false],
    'no previous commits' => [3601, 10, null, false, false],
    'pending history' => [3601, 10, CommitStatus::Pending, false, false],
    'rejected history' => [3601, 10, CommitStatus::Rejected, false, false],
    'deleted approved history' => [3601, 10, CommitStatus::Approved, true, false],
]);

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
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Initial commit');

    Queue::assertPushed(ModerateCommit::class, fn (ModerateCommit $job): bool => $job->commit->is($commit));
});

it('queues topic classification after creation for roots and quotes but not replies or empty posts', function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
    Queue::fake();
    $user = User::factory()->create();
    $action = resolve(CreateCommit::class);

    $root = $action->handle($user, 'Will PHP 8.6 ship this November?');
    $quote = $action->handle($user, 'PHP releases are worth discussing', quotedCommit: $root);
    $action->handle($user, 'I think so', parent: $root);
    $action->handle($user, '');

    Queue::assertPushed(ClassifyCommitTopics::class, 2);
    Queue::assertPushed(ClassifyCommitTopics::class, fn (ClassifyCommitTopics $job): bool => $job->commitIds === [$root->id] && $job->afterCommit === true);
    Queue::assertPushed(ClassifyCommitTopics::class, fn (ClassifyCommitTopics $job): bool => $job->commitIds === [$quote->id]);
});

it('queues topic classification even without a provider key', function (): void {
    config(['ai.providers.openai.key' => null]);
    Queue::fake();

    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Will PHP 8.6 ship this November?');

    Queue::assertPushed(ClassifyCommitTopics::class, fn (ClassifyCommitTopics $job): bool => $job->commitIds === [$commit->id]);
});

it('creates the commit before classification and keeps failed low priority jobs free of provider content', function (): void {
    config(['queue.default' => 'database', 'ai.providers.openai.key' => 'sk-test', 'app.admins' => ['topic-author']]);
    Bus::fake([CheckUserLevel::class, EmbedCommit::class]);
    Exceptions::fake();
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['error' => ['message' => 'Sensitive provider response']], 400)]);

    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(['username' => 'topic-author']), 'Will PHP 8.6 ship this November?');

    $this->assertModelExists($commit);
    $this->assertDatabaseHas('jobs', ['queue' => 'low']);
    Http::assertNothingSent();

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])->assertSuccessful();
    Http::assertNothingSent();

    foreach ([0, 60, 600] as $delay) {
        $this->travel($delay)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default,low', '--once' => true])->assertSuccessful();
    }

    expect($commit->refresh())->topic_decision->toBeNull()->status->toBe(CommitStatus::Approved);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    expect(DB::table('failed_jobs')->value('exception'))
        ->toContain('Topic classification failed for commit '.$commit->id)
        ->not->toContain('Sensitive provider response', $commit->body);
    Exceptions::assertReported(fn (CommitProcessingFailed $exception): bool => ! $exception->getPrevious() instanceof Throwable
        && ! str_contains($exception->getMessage(), 'Sensitive provider response'));
});

it('does not ask the ai about commits of admins', function (): void {
    config(['app.admins' => ['nunomaduro']]);
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(['username' => 'nunomaduro']), 'Initial commit');

    Queue::assertNotPushed(ModerateCommit::class);
});

it('queues ai review for a quote while preserving its images and chosen link card', function (): void {
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

it('does not embed commits without a body', function (): void {
    Storage::fake();
    Queue::fake();

    resolve(CreateCommit::class)->handle(User::factory()->create(), '', images: [UploadedFile::fake()->image('screenshot.png', 10, 10)]);

    Queue::assertPushed(ModerateCommit::class);
    Queue::assertNotPushed(EmbedCommit::class);
});

it('queues moderation and embedding even when the provider is misconfigured', function (): void {
    config(['ai.providers.openai.key' => null]);
    Queue::fake();

    $action = resolve(CreateCommit::class);

    $action->handle(User::factory()->create(), 'Initial commit');

    Queue::assertPushed(ModerateCommit::class);
    Queue::assertPushed(EmbedCommit::class);
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

    Queue::assertNotPushed(UnfurlLink::class);
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

    Queue::assertNotPushed(UnfurlLink::class);
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

    Queue::assertNotPushed(UnfurlLink::class);
});

it('attaches a poll with its options in order', function (): void {
    $action = resolve(CreateCommit::class);

    $commit = $action->handle(User::factory()->create(), 'Tabs or spaces?', poll: new NewPoll(['Tabs', 'Spaces', 'Both :fire:'], PollDuration::ThreeDays));

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

it('erases the stored images when the commit fails to save', function (): void {
    Storage::fake();

    expect(fn (): Commit => resolve(CreateCommit::class)->handle(
        User::factory()->create(),
        'Look at this',
        images: [UploadedFile::fake()->image('photo.jpg', 10, 10)],
        poll: new NewPoll(['Tabs', str_repeat('a', NewPoll::MaxOptionLength + 1)], PollDuration::OneHour),
    ))->toThrow(QueryException::class);

    expect(Commit::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
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
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, new ThreadEngagement(stargazers: 0, quoters: 0, repliers: 1, repliersAnsweredByAuthor: 0)), 1e-9);
});

it('lifts the quoted thread when an admin quotes', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $commit = Commit::factory()->create();

    resolve(CreateCommit::class)->handle(User::factory()->create(['username' => 'nunomaduro']), 'Worth a read', quotedCommit: $commit);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, new ThreadEngagement(stargazers: 0, quoters: 1, repliers: 0, repliersAnsweredByAuthor: 0)), 1e-9);
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
            $admin ? resolve(TheAlgorithm::class)->score($root->created_at, new ThreadEngagement(stargazers: 0, quoters: 1, repliers: 1, repliersAnsweredByAuthor: 0)) : $root->hot_score,
            1e-9,
        );
})->with([false, true]);

it('ignores reports when deciding whether a commit goes live', function (bool $reputable): void {
    Queue::fake();
    $user = User::factory()->create(['created_at' => now()->subHours(2)]);
    $reported = Commit::factory()->for($user)->create();
    if ($reputable) {
        followersOf($user, 10);
    }

    CommitReport::factory()->for($reported)->create();

    $commit = resolve(CreateCommit::class)->handle($user, 'Another commit', poll: new NewPoll(['Yes', 'No'], PollDuration::OneHour));

    expect($commit->status)->toBe($reputable ? CommitStatus::Approved : CommitStatus::Pending)
        ->and($commit->poll->opened_at !== null)->toBe($reputable);

    if ($reputable) {
        Queue::assertNotPushed(ModerateCommit::class);
    } else {
        Queue::assertPushed(ModerateCommit::class);
    }
})->with([true, false]);
