<?php

declare(strict_types=1);

use App\Actions\DeleteCommit;
use App\Actions\DeleteUser;
use App\Actions\UpdateCommitStatus;
use App\Enums\CommitStatus;
use App\Enums\Log;
use App\Enums\NotificationType;
use App\Enums\PollDuration;
use App\Jobs\ClassifyCommitTopics;
use App\Jobs\DetectCommitLanguage;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\User;
use App\Services\Features;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('may attach a poll to a commit', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Tabs or spaces?',
            'poll' => ['options' => ['Tabs', ' Spaces '], 'duration' => PollDuration::SevenDays->value],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectToRoute('log.show', Log::Following);

    $poll = $user->commits()->sole()->poll;

    expect($poll->duration)->toBe(PollDuration::SevenDays)
        ->and($poll->options->pluck('label')->all())->toBe(['Tabs', 'Spaces']);
});

it('keeps a poll when its link card is chosen or removed', function (?string $linkPreviewUrl): void {
    Queue::fake([UnfurlLink::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Compare https://example.com/first and https://example.com/second',
            'link_preview_url' => $linkPreviewUrl,
            'poll' => ['options' => ['First', 'Second'], 'duration' => PollDuration::OneDay->value],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectToRoute('log.show', Log::Following);

    $commit = $user->commits()->sole();

    expect($commit->poll->options->pluck('label')->all())->toBe(['First', 'Second'])
        ->and($commit->linkPreview?->url)->toBe($linkPreviewUrl);

    if ($linkPreviewUrl === null) {
        Queue::assertNotPushed(UnfurlLink::class);
    } else {
        Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
    }
})->with(['chosen' => 'https://example.com/second', 'removed' => null]);

it('keeps link card choices on quotes through approval without exposing quoted votes', function (?string $linkPreviewUrl): void {
    Queue::fake([ClassifyCommitTopics::class, UnfurlLink::class]);

    $user = User::factory()->create();
    $quotedAuthor = User::factory()->create(['username' => 'quoted-writer']);
    $quoted = Poll::factory()->for(Commit::factory()->for($quotedAuthor))->withOptions('Private A', 'Private B')->create();
    $voter = User::factory()->create(['username' => 'private-voter']);
    PollVote::factory()->for($quoted->options[0], 'option')->for($voter)->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Compare @quoted-writer https://example.com/first and https://example.com/second',
            'quoted_commit_id' => $quoted->commit_id,
            'link_preview_url' => $linkPreviewUrl,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectToRoute('log.show', Log::Following);

    $quote = $user->commits()->sole();

    expect($quote->quoted_commit_id)->toBe($quoted->commit_id)
        ->and($quote->poll)->toBeNull()
        ->and($quote->linkPreview?->url)->toBe($linkPreviewUrl);

    resolve(UpdateCommitStatus::class)->handle($quote, CommitStatus::Approved);

    $notification = $quotedAuthor->notifications()->sole();

    expect($quote->refresh()->status)->toBe(CommitStatus::Approved)
        ->and($user->notifications()->exists())->toBeFalse()
        ->and($notification->type)->toBe(NotificationType::Quoted)
        ->and($notification->actor_id)->toBe($user->id)
        ->and($notification->commit_id)->toBe($quote->id);

    auth()->logout();

    $this->get(route('commit.show', [$user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user', null)
            ->where('commit.poll', null)
            ->where('commit.quoted_commit.id', $quoted->commit_id)
            ->where('commit.quoted_commit.poll.options.0.label', 'Private A')
            ->where('commit.quoted_commit.poll.total_votes', null))
        ->assertDontSee('private-voter')
        ->assertDontSee($voter->id);
})->with(['chosen' => 'https://example.com/second', 'removed' => null]);

it('does not create a poll when its chosen link card is outside the body', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Choose https://example.com/first',
            'link_preview_url' => 'https://example.com/second',
            'poll' => ['options' => ['First', 'Second'], 'duration' => PollDuration::OneDay->value],
        ])
        ->assertSessionHasErrors('link_preview_url');

    expect($user->commits()->count())->toBe(0)
        ->and(Poll::query()->count())->toBe(0);
});

it('validates the poll', function (array $poll, array|string $errors): void {
    $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), ['body' => 'Tabs or spaces?', 'poll' => $poll])
        ->assertSessionHasErrors($errors);

    expect(Commit::query()->count())->toBe(0);
})->with([
    'one option' => [['options' => ['Tabs'], 'duration' => 1440], 'poll.options'],
    'five options' => [['options' => ['A', 'B', 'C', 'D', 'E'], 'duration' => 1440], 'poll.options'],
    'empty option' => [['options' => ['Tabs', '  '], 'duration' => 1440], 'poll.options.1'],
    'long option' => [['options' => ['Tabs', str_repeat('a', 51)], 'duration' => 1440], 'poll.options.1'],
    'duplicate options' => [['options' => ['Tabs', 'tabs'], 'duration' => 1440], 'poll.options.1'],
    'unknown duration' => [['options' => ['Tabs', 'Spaces'], 'duration' => 30], 'poll.duration'],
    'no duration' => [['options' => ['Tabs', 'Spaces']], 'poll.duration'],
    'no options' => [['duration' => 1440], 'poll.options'],
]);

it('may not attach a poll to a reply', function (): void {
    $parent = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Trending)
        ->post(route('commit.store'), [
            'body' => 'Tabs or spaces?',
            'parent_id' => $parent->id,
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 1440],
        ])
        ->assertSessionHasErrors('poll');

    expect($parent->replies()->count())->toBe(0);
});

it('may not attach a poll to a quote', function (): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->create();

    $this->actingAs($user)
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Tabs or spaces?',
            'quoted_commit_id' => $quoted->id,
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => PollDuration::OneDay->value],
        ])
        ->assertRedirectToRoute('log.show', Log::Following)
        ->assertSessionHasErrors(['poll' => 'The poll field prohibits quoted commit id from being present.']);

    expect($user->commits()->exists())->toBeFalse()
        ->and(Poll::query()->exists())->toBeFalse();
});

it('may not attach a poll to a commit with images', function (): void {
    Storage::fake();

    $this->actingAs(User::factory()->create())
        ->fromRoute('log.show', Log::Following)
        ->post(route('commit.store'), [
            'body' => 'Tabs or spaces?',
            'images' => [UploadedFile::fake()->image('desk.png', 10, 10)],
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 1440],
        ])
        ->assertSessionHasErrors('poll');

    expect(Commit::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBeEmpty();
});

it('highlights code in the question of a poll like in any commit', function (): void {
    Poll::factory()->for(Commit::factory()->state(['body' => 'Do you still run `composer dump`?']))->withOptions('Yes', 'No')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('log.show', Log::Trending))
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.poll.options.0.label', 'Yes')
            ->where('commits.data.0.body_segments.1', ['type' => 'code', 'code' => 'composer dump']));
});

it('shows polls in the logs without the results before voting', function (Log $log): void {
    $user = User::factory()->create();
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $user->following()->attach($poll->commit->user);
    PollVote::factory()->for($poll->options[0], 'option')->create();

    $this->actingAs($user)
        ->get(route('log.show', $log))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.poll.has_voted', false)
            ->where('commits.data.0.poll.total_votes', null)
            ->where('commits.data.0.poll.options.0.label', 'Tabs')
            ->where('commits.data.0.poll.options.0.votes_count', null));
})->with(Log::cases());

it('shows the poll of a commit and of its ancestor', function (): void {
    $user = User::factory()->create();
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $reply = Commit::factory()->for($poll->commit, 'parent')->create();
    PollVote::factory()->for($poll->options[1], 'option')->for($user)->create();

    $this->actingAs($user)
        ->get(route('commit.show', [$poll->commit->user, $poll->commit]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.poll.total_votes', 1)
            ->where('commit.poll.options.1.voted', true));

    $this->get(route('commit.show', [$reply->user, $reply]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.poll', null)
            ->where('ancestors.0.poll.options.1.voted', true));
});

it('shows the poll of a quoted commit with the same results as the commit itself', function (): void {
    $voter = User::factory()->create();
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $quote = Commit::factory()->for($poll->commit, 'quotedCommit')->create();
    PollVote::factory()->for($poll->options[1], 'option')->for($voter)->create();

    $this->actingAs($voter)
        ->get(route('commit.show', [$quote->user, $quote]))
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.poll.total_votes', 1)
            ->where('commit.quoted_commit.poll.options.1.voted', true));

    $this->actingAs($poll->commit->user)
        ->get(route('commit.show', [$quote->user, $quote]))
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.poll.has_voted', false)
            ->where('commit.quoted_commit.poll.total_votes', 1));

    $this->actingAs($quote->user)
        ->get(route('commit.show', [$quote->user, $quote]))
        ->assertInertia(fn ($page) => $page
            ->where('commit.quoted_commit.poll.options.1.label', 'Spaces')
            ->where('commit.quoted_commit.poll.total_votes', null));
});

it('shows the poll of a quoted commit in moderation and after undoing a deletion', function (): void {
    config(['app.admins' => ['admin']]);
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $quote = Commit::factory()->pending()->for($poll->commit, 'quotedCommit')->create();

    $this->actingAs(User::factory()->create(['username' => 'admin']))
        ->get(route('moderation.show'))
        ->assertInertia(fn ($page) => $page->where('commits.data.0.quoted_commit.poll.options.0.label', 'Tabs'));

    resolve(DeleteCommit::class)->handle($quote);

    $this->actingAs($quote->user)
        ->post(route('commit-restoration.store', $quote))
        ->assertInertiaFlash('restored.quoted_commit.poll.options.0.label', 'Tabs');
});

it('shows polls on profiles and starred commits', function (): void {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $pinned = Poll::factory()->for(Commit::factory()->for($author))->withOptions('Tabs', 'Spaces')->create();
    $latest = Poll::factory()->for(Commit::factory()->for($author))->withOptions('Vim', 'Emacs')->create();
    $author->update(['pinned_commit_id' => $pinned->commit_id]);
    $user->starredCommits()->attach($latest->commit);

    $this->actingAs($user)
        ->get(route('user.show', $author))
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.poll.options.0.label', 'Tabs')
            ->where('commits.data.0.poll.options.0.label', 'Vim'));

    $this->get(route('starred.index'))
        ->assertInertia(fn ($page) => $page->where('commits.data.0.poll.options.0.label', 'Vim'));
});

it('shows the poll of a pending commit to moderators', function (): void {
    config(['app.admins' => ['admin']]);
    Poll::factory()->for(Commit::factory()->pending())->withOptions('Tabs', 'Spaces')->unopened()->create();

    $this->actingAs(User::factory()->create(['username' => 'admin']))
        ->get(route('moderation.show'))
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.poll.closes_at', null)
            ->where('commits.data.0.poll.options.1.label', 'Spaces'));
});

it('loads the polls of the log with a bounded number of queries', function (): void {
    Queue::fake([DetectCommitLanguage::class]);
    resolve(Features::class)->all();
    $user = User::factory()->create(['log' => Log::Trending, 'last_seen_at' => today()]);
    Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $this->actingAs($user);
    DB::enableQueryLog();

    $this->get(route('log.show', Log::Trending))->assertOk();
    $singleCount = count(DB::getQueryLog());
    Poll::factory()->count(19)->withOptions('Vim', 'Emacs', 'Nano')->create();
    PollVote::factory()->count(5)->create();
    DB::flushQueryLog();

    $this->get(route('log.show', Log::Trending))->assertOk()->assertInertia(fn ($page) => $page->has('commits.data', 20));
    expect(count(DB::getQueryLog()))->toBe($singleCount);
    DB::disableQueryLog();
});

it('shows public polls to guests while hiding open results and voter identities', function (string $endpoint, bool $closed): void {
    $factory = Poll::factory()->withOptions('Tabs', 'Spaces');
    $poll = ($closed ? $factory->closed() : $factory)->create();
    $voter = User::factory()->create(['username' => 'private-voter']);
    PollVote::factory()->for($poll->options[0], 'option')->for($voter)->create();

    [$url, $path, $component] = match ($endpoint) {
        'commit' => [route('commit.show', [$poll->commit->user, $poll->commit]), 'commit.poll', 'commit/show'],
        'ancestor' => (function () use ($poll): array {
            $reply = Commit::factory()->for($poll->commit, 'parent')->create();

            return [route('commit.show', [$reply->user, $reply]), 'ancestors.0.poll', 'commit/show'];
        })(),
        'profile' => [route('user.show', $poll->commit->user), 'commits.data.0.poll', 'user/show'],
        'pinned' => (function () use ($poll): array {
            $poll->commit->user->update(['pinned_commit_id' => $poll->commit_id]);

            return [route('user.show', $poll->commit->user), 'pinnedCommit.poll', 'user/show'];
        })(),
    };

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component($component)
            ->where('auth.user', null)
            ->where("{$path}.has_voted", false)
            ->where("{$path}.total_votes", $closed ? 1 : null)
            ->where("{$path}.options.0.label", 'Tabs')
            ->where("{$path}.options.0.votes_count", $closed ? 1 : null)
            ->where("{$path}.options.0.voted", false)
            ->where("{$path}.options.1.votes_count", $closed ? 0 : null))
        ->assertDontSee('private-voter')
        ->assertDontSee($voter->id);
})->with(['commit', 'ancestor', 'profile', 'pinned'])->with([false, true]);

it('keeps guest poll results private around an authorless account deletion tombstone', function (): void {
    $poll = Poll::factory()->withOptions('Tabs', 'Spaces')->create();
    $voter = User::factory()->create(['username' => 'private-voter']);
    PollVote::factory()->for($poll->options[0], 'option')->for($voter)->create();
    $deleted = User::factory()->create(['username' => 'deleted-author']);
    $parent = Commit::factory()->for($deleted)->for($poll->commit, 'parent')->create(['body' => 'Deleted reply body']);
    $reply = Commit::factory()->for($parent, 'parent')->create();

    resolve(DeleteUser::class)->handle($deleted);

    $this->get(route('commit.show', [$reply->user, $reply]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user', null)
            ->has('ancestors', 2)
            ->where('ancestors.0.poll.has_voted', false)
            ->where('ancestors.0.poll.total_votes', null)
            ->where('ancestors.0.poll.options.0.votes_count', null)
            ->where('ancestors.1.id', $parent->id)
            ->missing('ancestors.1.user')
            ->missing('ancestors.1.body')
            ->missing('ancestors.1.poll'))
        ->assertDontSee('deleted-author')
        ->assertDontSee('Deleted reply body')
        ->assertDontSee('private-voter')
        ->assertDontSee($voter->id);
});

it('keeps poll details out of public read endpoints', function (string $endpoint): void {
    $poll = Poll::factory()
        ->for(Commit::factory()->state(['body' => 'Which editor do you prefer?']))
        ->withOptions('Private option A', 'Private option B')
        ->closed()
        ->create();
    $voter = User::factory()->create(['username' => 'private-voter']);
    PollVote::factory()->for($poll->options[0], 'option')->for($voter)->create();

    $url = match ($endpoint) {
        'commit' => route('api-commit.show', $poll->commit),
        'user commits' => route('api-user-commit.index', $poll->commit->user),
        'feed' => route('user-feed.show', $poll->commit->user),
    };

    $this->get($url)
        ->assertOk()
        ->assertSee('Which editor do you prefer?')
        ->assertDontSee('Private option A')
        ->assertDontSee('Private option B')
        ->assertDontSee('private-voter')
        ->assertDontSee($poll->id);
})->with(['commit', 'user commits', 'feed']);
