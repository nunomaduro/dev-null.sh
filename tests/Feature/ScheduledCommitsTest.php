<?php

declare(strict_types=1);

use App\Actions\DeleteScheduledCommit;
use App\Actions\DeleteUser;
use App\Actions\UpdateScheduledCommitSubmittedAt;
use App\Enums\CommitStatus;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Queries\UserActivityQuery;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    config(['app.admins' => ['admin']]);
});

it('rejects polls instead of silently scheduling only their question', function (): void {
    $this->actingAs(User::factory()->create())
        ->from(route('log.show', 'following'))
        ->post(route('scheduled-commit.store'), [
            'body' => 'Tabs or spaces?',
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 1440],
        ])
        ->assertSessionHasErrors('poll');

    expect(ScheduledCommit::query()->count())->toBe(0)
        ->and(Commit::query()->count())->toBe(0);
});

it('stores an offset timestamp as UTC without creating a commit', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->from(route('log.show', 'following'))
        ->post(route('scheduled-commit.store'), [
            'body' => '  Shipped the parser benchmark.  ',
            'scheduled_at' => '2026-10-16T10:30:00+05:30',
            'user_id' => User::factory()->create()->id,
            'status' => 'approved',
        ])->assertRedirectToRoute('log.show', 'following')->assertSessionHasNoErrors();

    $scheduled = ScheduledCommit::query()->sole();
    expect($scheduled->user_id)->toBe($user->id)
        ->and($scheduled->body)->toBe('Shipped the parser benchmark.')
        ->and($scheduled->scheduled_at->toIso8601String())->toBe('2026-10-16T05:00:00+00:00')
        ->and(Commit::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('requires authentication for every scheduled endpoint', function (): void {
    $scheduled = ScheduledCommit::factory()->create();
    $this->get(route('scheduled-commit.index'))->assertRedirectToRoute('home');
    $this->post(route('scheduled-commit.store'), [])->assertRedirectToRoute('home');
    $this->delete(route('scheduled-commit.destroy', $scheduled))->assertRedirectToRoute('home');
});

it('rejects invalid times and bodies', function (array $payload, string $field): void {
    $this->actingAs(User::factory()->create())->post(route('scheduled-commit.store'), [
        'body' => 'Shipped a scheduling feature.',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        ...$payload,
    ])->assertSessionHasErrors($field);
    expect(ScheduledCommit::query()->count())->toBe(0);
})->with([
    'missing offset' => [['scheduled_at' => '2026-10-16T12:00:00'], 'scheduled_at'],
    'invalid date' => [['scheduled_at' => '2026-10-32T12:00:00+00:00'], 'scheduled_at'],
    'now' => [['scheduled_at' => '2026-10-15T12:00:00+00:00'], 'scheduled_at'],
    'past' => [['scheduled_at' => '2026-10-15T11:59:00+00:00'], 'scheduled_at'],
    'beyond thirty days' => [['scheduled_at' => '2026-11-14T12:00:01+00:00'], 'scheduled_at'],
    'whitespace' => [['body' => '    '], 'body'],
    'too long' => [['body' => str_repeat('a', 4001)], 'body'],
]);

it('accepts the scheduling window boundary and unicode body', function (): void {
    $this->actingAs(User::factory()->create())->post(route('scheduled-commit.store'), [
        'body' => str_repeat('é', 4000),
        'scheduled_at' => now()->addDays(30)->toIso8601String(),
    ])->assertSessionHasNoErrors();
    expect(ScheduledCommit::query()->count())->toBe(1);
});

it('does not permit scheduling replies to any content', function (string $state): void {
    $parent = Commit::factory()->create(['status' => $state === 'deleted' ? CommitStatus::Approved : CommitStatus::from($state)]);
    if ($state === 'deleted') {
        $parent->delete();
    }

    $this->actingAs($parent->user)->post(route('scheduled-commit.store'), [
        'body' => 'This must not become a reply.',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'parent_id' => $parent->id,
    ])->assertSessionHasErrors('parent_id');
    expect(ScheduledCommit::query()->count())->toBe(0);
})->with(['approved', 'pending', 'rejected', 'deleted']);

it('limits outstanding submissions per author and frees a slot on cancellation', function (): void {
    $user = User::factory()->create();
    $items = ScheduledCommit::factory()->for($user)->count(10)->create();
    $payload = ['body' => 'One more scheduled commit.', 'scheduled_at' => now()->addDay()->toIso8601String()];

    $this->actingAs($user)->post(route('scheduled-commit.store'), $payload)->assertSessionHasErrors('scheduled_at');
    $this->delete(route('scheduled-commit.destroy', $items->first()))->assertSessionHasNoErrors();
    $this->post(route('scheduled-commit.store'), $payload)->assertSessionHasNoErrors();

    expect(ScheduledCommit::query()->whereNull('canceled_at')->count())->toBe(10);
});

it('shares the existing commit rate limit', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)->post(route('commit.store'), ['body' => 'An ordinary commit.']);
    }

    $this->post(route('scheduled-commit.store'), [
        'body' => 'Must be throttled.', 'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertRedirect();
    expect(ScheduledCommit::query()->count())->toBe(0);
});

it('keeps schedules out of logs profiles activity previews and moderation until due', function (): void {
    $user = User::factory()->create();
    $admin = User::factory()->create(['username' => 'admin']);
    $scheduled = ScheduledCommit::factory()->for($user)->create(['body' => 'Private future parser release.']);

    foreach ([$user, $admin, User::factory()->create()] as $viewer) {
        $this->actingAs($viewer)->get(route('log.show', 'trending'))->assertInertia(fn ($page) => $page->has('commits.data', 0))->assertDontSee($scheduled->body);
        $this->get(route('user.show', $user))->assertInertia(fn ($page) => $page->has('commits.data', 0)->where('user.commits_count', 0));
        $this->get(route('user-preview.show', $user))->assertJsonPath('user.commits_count', 0);
        expect(array_sum(array_column(resolve(UserActivityQuery::class)->get($user, $viewer)['buckets'], 'count')))->toBe(0);
    }

    $this->actingAs($admin)->get(route('moderation.show'))->assertInertia(fn ($page) => $page->has('commits.data', 0)->where('hasUnmoderatedCommits', false));
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::query()->count())->toBe(0);

    $this->travelTo($scheduled->scheduled_at);
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    $this->get(route('moderation.show'))->assertInertia(fn ($page) => $page->has('commits.data', 1)->where('hasUnmoderatedCommits', true));
});

it('splits scheduled bodies into segments, with an excerpt of the long ones', function (): void {
    $user = User::factory()->create();
    ScheduledCommit::factory()->for($user)->create(['body' => 'run `make`', 'scheduled_at' => now()->addHour()]);
    ScheduledCommit::factory()->for($user)->create(['body' => str_repeat('again ', 60), 'scheduled_at' => now()->addHours(2)]);

    $this->actingAs($user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->where('scheduledCommits.data.0.body_segments', [['type' => 'text', 'text' => 'run '], ['type' => 'code', 'code' => 'make']])
        ->where('scheduledCommits.data.0.preview_segments', null)
        ->where('scheduledCommits.data.1.preview_segments', [['type' => 'text', 'text' => mb_rtrim(str_repeat('again ', 46)).'…']]));
});

it('only lists the current authors schedules and forbids canceling another authors item', function (): void {
    $mine = ScheduledCommit::factory()->create();
    $other = ScheduledCommit::factory()->create();

    $this->actingAs($mine->user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->component('scheduled-commits/index')->has('scheduledCommits.data', 1)->where('scheduledCommits.data.0.id', $mine->id))
        ->assertDontSee($other->body);
    $this->delete(route('scheduled-commit.destroy', $other))->assertForbidden();
    $this->actingAs(User::factory()->create(['username' => 'admin']))->delete(route('scheduled-commit.destroy', $mine))->assertForbidden();
    expect($other->fresh()->canceled_at)->toBeNull();
});

it('keeps scheduled content private in the public API and feed until approved delivery', function (bool $admin): void {
    $user = User::factory()->create(['username' => $admin ? 'admin' : 'octocat']);
    $scheduled = ScheduledCommit::factory()->for($user)->create(['body' => 'Private future release :rocket:']);

    $this->getJson(route('api-user-commit.index', $user))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('api-commit.show', $scheduled->id))->assertNotFound();
    $this->get(route('user-feed.show', $user))->assertOk()->assertDontSee('Private future release');

    $this->travelTo($scheduled->scheduled_at);
    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    $commit = Commit::query()->sole();
    expect($commit->body)->toBe('Private future release 🚀');

    if ($admin) {
        $this->getJson(route('api-user-commit.index', $user))->assertOk()->assertJsonPath('data.0.id', $commit->id);
        $this->getJson(route('api-commit.show', $commit))->assertOk()->assertJsonPath('data.body', $commit->body);
        $this->get(route('user-feed.show', $user))->assertOk()->assertSee($commit->body);
    } else {
        $this->getJson(route('api-user-commit.index', $user))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api-commit.show', $commit))->assertNotFound();
        $this->get(route('user-feed.show', $user))->assertOk()->assertDontSee('Private future release');
    }
})->with([false, true]);

it('submits once at the due time using the authors current approval privileges', function (bool $admin): void {
    $user = User::factory()->create();
    $scheduled = ScheduledCommit::factory()->for($user)->create();
    if ($admin) {
        $user->update(['username' => 'admin']);
    }

    $action = resolve(UpdateScheduledCommitSubmittedAt::class);
    expect($action->handle($scheduled->id))->toBeFalse();
    $this->travelTo($scheduled->scheduled_at);
    expect($action->handle($scheduled->id))->toBeTrue()
        ->and($action->handle($scheduled->id))->toBeFalse();
    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    $commit = Commit::query()->sole();
    expect($commit->user_id)->toBe($user->id)
        ->and($commit->status)->toBe($admin ? CommitStatus::Approved : CommitStatus::Pending)
        ->and($commit->created_at->equalTo(now()))->toBeTrue()
        ->and($scheduled->fresh()->body)->toBeNull()
        ->and($scheduled->fresh()->commit_id)->toBe($commit->id);

    $this->actingAs($user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->where('scheduledCommits.data.0.status', $admin ? 'approved' : 'pending')
        ->where('scheduledCommits.data.0.body', null));
})->with([false, true]);

it('keeps the first link as the scheduled delivery preview', function (): void {
    $user = User::factory()->create();
    Queue::fake([UnfurlLink::class]);

    $this->actingAs($user)->post(route('scheduled-commit.store'), [
        'body' => 'Shipped https://example.com/first and https://example.com/second',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertSessionHasNoErrors();

    $scheduled = ScheduledCommit::query()->sole();
    $this->travelTo($scheduled->scheduled_at);
    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    $commit = Commit::query()->sole();
    expect($commit->linkPreview->url)->toBe('https://example.com/first');

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
});

it('cancels a due item before claim and never submits it', function (): void {
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    $this->actingAs($scheduled->user)->delete(route('scheduled-commit.destroy', $scheduled))->assertRedirect();
    $this->delete(route('scheduled-commit.destroy', $scheduled))->assertRedirect();
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::query()->count())->toBe(0)
        ->and($scheduled->fresh()->canceled_at)->not->toBeNull()
        ->and($scheduled->fresh()->body)->toBeNull();
    $this->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page->has('scheduledCommits.data', 0));
});

it('does not cancel or resend a submitted item even if its commit is deleted', function (): void {
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id);
    expect(resolve(DeleteScheduledCommit::class)->handle($scheduled->user, $scheduled))->toBeFalse();
    $commit = Commit::query()->sole();
    $commit->delete();
    $this->actingAs($scheduled->user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->where('scheduledCommits.data.0.status', 'deleted')->where('scheduledCommits.data.0.body', null));
    $commit->forceDelete();
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::withTrashed()->count())->toBe(0);
});

it('reflects rejection and approval without retaining a second body', function (CommitStatus $status): void {
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id);
    Commit::query()->sole()->update(['status' => $status]);
    $this->actingAs($scheduled->user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->where('scheduledCommits.data.0.status', $status->value)->where('scheduledCommits.data.0.body', null));
})->with([CommitStatus::Approved, CommitStatus::Rejected]);

it('rolls back a failed original create action and retries without loss or duplicates', function (): void {
    $first = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    $second = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    Exceptions::fake();
    $fail = true;
    Commit::created(function (Commit $commit) use (&$fail, $first): void {
        throw_if($fail && $commit->user_id === $first->user_id, RuntimeException::class, 'Simulated create failure after insert.');
    });
    try {
        $this->artisan('commits:submit-scheduled')->assertFailed();
        expect($first->fresh()->submitted_at)->toBeNull()
            ->and($first->fresh()->body)->toBe($first->body)
            ->and($first->fresh()->commit_id)->toBeNull()
            ->and($second->fresh()->submitted_at)->not->toBeNull()
            ->and(Commit::query()->count())->toBe(1);

        $fail = false;
        $this->artisan('commits:submit-scheduled')->assertSuccessful();
        $this->artisan('commits:submit-scheduled')->assertSuccessful();
        expect(Commit::query()->count())->toBe(2)
            ->and($first->fresh()->submitted_at)->not->toBeNull();
    } finally {
        Commit::flushEventListeners();
    }
});

it('rolls back creation if saving the delivery receipt fails', function (): void {
    config(['queue.default' => 'database']);
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now(), 'body' => 'Read https://example.com/docs']);
    ScheduledCommit::updating(function (): void {
        throw new RuntimeException('Simulated receipt failure.');
    });
    try {
        expect(fn () => resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id))->toThrow(RuntimeException::class);
        expect(Commit::query()->count())->toBe(0)->and($scheduled->fresh()->submitted_at)->toBeNull();
        $this->assertDatabaseCount('jobs', 0);
    } finally {
        ScheduledCommit::flushEventListeners();
    }

    expect(resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id))->toBeTrue();
    expect(Commit::query()->count())->toBe(1);
    $this->assertDatabaseCount('jobs', 1);
});

it('rejects a competing claim and cancellation while creation is in progress', function (): void {
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    $competingClaim = null;
    $competingCancellation = null;
    Commit::creating(function () use ($scheduled, &$competingClaim, &$competingCancellation): void {
        $competingClaim = resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id);
        $competingCancellation = resolve(DeleteScheduledCommit::class)->handle($scheduled->user, $scheduled);
    });
    try {
        expect(resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id))->toBeTrue()
            ->and($competingClaim)->toBeFalse()
            ->and($competingCancellation)->toBeFalse()
            ->and(Commit::query()->count())->toBe(1);
    } finally {
        Commit::flushEventListeners();
    }
});

it('bounds each scheduler run and drains remaining due items on the next run', function (): void {
    ScheduledCommit::factory()->for(User::factory()->create())->count(101)->create(['scheduled_at' => now()]);
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::query()->count())->toBe(100);
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::query()->count())->toBe(101);
});

it('deletes scheduled content with the account and handles stale delivery attempts', function (): void {
    $scheduled = ScheduledCommit::factory()->create(['scheduled_at' => now()]);
    resolve(DeleteUser::class)->handle($scheduled->user);
    expect(ScheduledCommit::query()->count())->toBe(0)
        ->and(resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id))->toBeFalse();
    $this->artisan('commits:submit-scheduled')->assertSuccessful();
    expect(Commit::query()->count())->toBe(0);
});

it('paginates author receipts while keeping outstanding items first', function (): void {
    $user = User::factory()->create();
    ScheduledCommit::factory()->for($user)->count(21)->create(['submitted_at' => now()->subDay(), 'body' => null]);
    $active = ScheduledCommit::factory()->for($user)->create();
    $this->actingAs($user)->get(route('scheduled-commit.index'))->assertInertia(fn ($page) => $page
        ->has('scheduledCommits.data', 20)->where('scheduledCommits.data.0.id', $active->id)->has('scheduledCommits.next_page_url'));
});
