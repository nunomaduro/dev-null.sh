<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\DeleteCommit;
use App\Actions\DeleteUser;
use App\Actions\UpdateCommitStatus;
use App\Actions\UpdateCommitStatuses;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['app.admins' => ['admin']]);
});

it('does not notify an author when approving a pending commit', function (): void {
    $commit = Commit::factory()->pending()->create();
    $stale = $commit->fresh();

    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);
    resolve(UpdateCommitStatus::class)->handle($stale, CommitStatus::Approved);

    expect($commit->user->notifications()->count())->toBe(0);
});

it('does not notify authors in a bulk approval and preserves reply notifications', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->pending()->for($parent, 'parent')->create();
    $commit = Commit::factory()->pending()->create();
    $rejected = Commit::factory()->rejected()->create();
    $deleted = Commit::factory()->pending()->create();
    $deleted->delete();

    $ids = [$reply->id, $commit->id, $rejected->id, $deleted->id];

    resolve(UpdateCommitStatuses::class)->handle($ids, CommitStatus::Approved, onlyPending: true);
    resolve(UpdateCommitStatuses::class)->handle($ids, CommitStatus::Approved, onlyPending: true);

    expect($reply->user->notifications()->count())->toBe(0)
        ->and($commit->user->notifications()->count())->toBe(0)
        ->and($parent->user->notifications()->sole()->type->value)->toBe('replied')
        ->and($rejected->fresh()->status)->toBe(CommitStatus::Rejected)
        ->and($rejected->user->notifications()->count())->toBe(0)
        ->and($deleted->user->notifications()->count())->toBe(0);
});

it('notifies mentioned users once a commit is approved', function (): void {
    $mentioned = User::factory()->create(['username' => 'taylorotwell']);
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Pairing with @taylorotwell today');

    expect($mentioned->notifications()->count())->toBe(0);

    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);

    $notice = $mentioned->notifications()->sole();

    expect($notice->type->value)->toBe('mentioned')
        ->and($notice->actor_id)->toBe($commit->user_id)
        ->and($notice->commit_id)->toBe($commit->id);
});

it('stores a concise rejection reason privately and leaves repeated rejections unchanged', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commit = Commit::factory()->pending()->create();

    $this->actingAs($admin)->fromRoute('moderation.show')
        ->post(route('commit-rejection.store', $commit), ['reason' => '  Please remove the access token.  '])
        ->assertRedirectToRoute('moderation.show');
    $this->post(route('commit-rejection.store', $commit), ['reason' => 'A different reason'])
        ->assertRedirect();

    $notice = $commit->user->notifications()->sole();
    expect($notice->type->value)->toBe('rejected')
        ->and($notice->reason)->toBe('Please remove the access token.');

    $this->actingAs($commit->user)->get(route('log.show', 'trending'))
        ->assertInertia(fn ($page) => $page->where('auth.unreadNotificationsCount', 1));
    $this->get(route('notification.index'))
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.type', 'rejected')
            ->where('notifications.data.0.reason', $notice->reason)
            ->where('notifications.data.0.commit.id', $commit->id)
            ->where('notifications.data.0.read_at', now()->startOfSecond()->toJSON()));
    expect($notice->fresh()->read_at)->not->toBeNull();

    $this->get(route('commit.show', [$commit->user, $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.status', 'rejected')
        ->missing('commit.reason')
        ->missing('commit.rejection_reason')
        ->where('auth.unreadNotificationsCount', 0));

    $this->actingAs(User::factory()->create())->get(route('notification.index'))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 0));
    $this->get(route('commit.show', [$commit->user, $commit]))->assertNotFound();
    $this->get(route('user.show', $commit->user))
        ->assertInertia(fn ($page) => $page->has('commits.data', 0))
        ->assertDontSee($notice->reason);
    $this->get(route('log.show', 'trending'))
        ->assertInertia(fn ($page) => $page->has('commits.data', 0))
        ->assertDontSee($notice->reason);
    auth()->logout();
    $this->get(route('commit.show', [$commit->user, $commit]))->assertNotFound();
});

it('validates rejection reasons before changing status', function (mixed $reason): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commit = Commit::factory()->pending()->create();

    $this->actingAs($admin)->post(route('commit-rejection.store', $commit), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect($commit->fresh()->status)->toBe(CommitStatus::Pending)
        ->and(Notification::query()->count())->toBe(0);
})->with(['too long' => str_repeat('x', 281), 'array' => [['bad']], 'number' => 12]);

it('accepts an optional rejection reason', function (?string $reason): void {
    $commit = Commit::factory()->pending()->create();
    $admin = User::factory()->create(['username' => 'admin']);

    $this->actingAs($admin)->post(route('commit-rejection.store', $commit), ['reason' => $reason])->assertRedirect();

    expect($commit->user->notifications()->sole()->reason)->toBeNull();
})->with([null, '', '   ']);

it('does not permit guests or other authors to moderate', function (string $route): void {
    $commit = Commit::factory()->pending()->create();
    $url = route($route, $route === 'moderation.update' ? [] : $commit);
    $method = $route === 'moderation.update' ? 'put' : 'post';
    $data = ['commits' => [$commit->id], 'reason' => 'Private'];

    $this->{$method}($url, $data)->assertRedirectToRoute('home');
    $this->actingAs($commit->user)->{$method}($url, $data)->assertForbidden();

    expect($commit->fresh()->status)->toBe(CommitStatus::Pending)
        ->and(Notification::query()->count())->toBe(0);
})->with(['commit-approval.store', 'commit-rejection.store', 'moderation.update']);

it('does not moderate deleted commits even with stale models', function (): void {
    $commit = Commit::factory()->pending()->create();
    $commit->delete();

    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected);

    expect($commit->fresh()->status)->toBe(CommitStatus::Pending)
        ->and(Notification::query()->count())->toBe(0);
});

it('does not send moderation notices for initial autoapproval or unchanged approved status', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commit = resolve(CreateCommit::class)->handle($admin, 'Shipped a smaller query planner.');

    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);

    expect($admin->notifications()->count())->toBe(0);
});

it('keeps only the current moderation decision relevant and notifies rejections but not approvals', function (): void {
    $commit = Commit::factory()->pending()->create();
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'Please add context.');
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);

    expect($commit->user->notifications()->relevant()->count())->toBe(0);
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'Please remove the token.');
    expect($commit->user->notifications()->relevant()->sole()->type->value)->toBe('rejected')
        ->and($commit->user->notifications()->relevant()->sole()->reason)->toBe('Please remove the token.');
});

it('rolls back notices and moderation together', function (): void {
    $commit = Commit::factory()->pending()->create();

    DB::beginTransaction();
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Approved);
    DB::rollBack();

    expect($commit->fresh()->status)->toBe(CommitStatus::Pending)
        ->and(Notification::query()->count())->toBe(0);
});

it('keeps a read notice read when the same status is submitted again', function (): void {
    $commit = Commit::factory()->pending()->create();
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'Please add context.');
    $notice = $commit->user->notifications()->sole();
    $notice->update(['read_at' => now()]);

    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'A different reason');

    expect($commit->user->notifications()->sole()->id)->toBe($notice->id)
        ->and($notice->fresh()->read_at)->not->toBeNull()
        ->and($commit->user->notifications()->relevant()->unread()->count())->toBe(0);
});

it('keeps a surviving reply visible when its parent is soft deleted', function (): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->pending()->for($parent, 'parent')->create();
    resolve(UpdateCommitStatus::class)->handle($reply, CommitStatus::Approved);

    resolve(DeleteCommit::class)->handle($parent);
    resolve(DeleteCommit::class)->handle($parent);

    expect($parent->user->notifications()->count())->toBe(0)
        ->and($reply->user->notifications()->count())->toBe(0);
    $this->actingAs($reply->user)->get(route('commit.show', [$reply->user, $reply]))->assertOk();
});

it('hides moderation notices when their commit is pending, deleted, or owned by another recipient', function (string $state): void {
    $commit = Commit::factory()->pending()->create();
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'Private reason');
    $notice = $commit->user->notifications()->sole();

    if ($state === 'pending') {
        $commit->refresh()->update(['status' => CommitStatus::Pending]);
    } elseif ($state === 'deleted') {
        $commit->delete();
    } else {
        $notice->update(['recipient_id' => User::factory()->create()->id]);
    }

    expect(Notification::query()->relevant()->count())->toBe(0);
})->with(['pending', 'deleted', 'other recipient']);

it('cleans up moderation notices with a commit or author', function (string $target): void {
    $commit = Commit::factory()->pending()->create();
    resolve(UpdateCommitStatus::class)->handle($commit, CommitStatus::Rejected, 'Private reason');
    $notice = $commit->user->notifications()->sole();

    if ($target === 'commit') {
        resolve(DeleteCommit::class)->handle($commit);
    } else {
        resolve(DeleteUser::class)->handle($commit->user);
    }

    expect($notice->fresh())->toBeNull();
})->with(['commit', 'author']);
