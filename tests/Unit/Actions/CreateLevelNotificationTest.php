<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\CreateCommitStar;
use App\Actions\CreateLevelNotification;
use App\Actions\UpdateCommitStatuses;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\CheckUserLevel;
use App\Jobs\ClassifyCommitTopics;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Commits made today all count toward a one-day streak: 10 XP each, plus 15 for the streak. Level 2 starts at 50 XP,
 * level 3 at 150.
 *
 * @return list<int>
 */
function levelNotifications(User $user): array
{
    return Notification::query()
        ->where('recipient_id', $user->id)
        ->where('type', NotificationType::LeveledUp)
        ->orderBy('level')
        ->pluck('level')
        ->all();
}

it('notifies the level the user reached, as their own actor', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(4)->create();

    resolve(CreateLevelNotification::class)->handle($user);

    $notification = Notification::query()->where('recipient_id', $user->id)->sole();

    expect($notification->type)->toBe(NotificationType::LeveledUp)
        ->and($notification->level)->toBe(2)
        ->and($notification->actor_id)->toBe($user->id)
        ->and($notification->commit_id)->toBeNull()
        ->and($notification->read_at)->toBeNull();
});

it('does not notify the level everyone starts at', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(3)->create();

    resolve(CreateLevelNotification::class)->handle($user);

    expect(levelNotifications($user))->toBe([]);
});

it('notifies each level once, even after losing and earning back the XP', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->for($user)->count(4)->create();
    $action = resolve(CreateLevelNotification::class);

    $action->handle($user);
    $commits->first()?->delete();
    $action->handle($user);
    Commit::factory()->for($user)->create();
    $action->handle($user);

    expect(levelNotifications($user))->toBe([2]);
});

it('only notifies the level reached, not the ones skipped on the way', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(14)->create();

    resolve(CreateLevelNotification::class)->handle($user);

    expect(levelNotifications($user))->toBe([3]);
});

it('counts only the XP everyone can see', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(3)->create();
    Commit::factory()->for($user)->pending()->count(5)->create();

    resolve(CreateLevelNotification::class)->handle($user);

    expect(levelNotifications($user))->toBe([]);
});

it('checks the level of an author whose commit is approved', function (): void {
    config(['queue.default' => 'sync']);
    Queue::fake([ClassifyCommitTopics::class]);

    $user = User::factory()->create();
    Commit::factory()->for($user)->count(3)->create();
    $pending = Commit::factory()->for($user)->pending()->create();

    resolve(UpdateCommitStatuses::class)->handle([$pending->id], CommitStatus::Approved);

    expect(levelNotifications($user))->toBe([2]);
});

it('checks the level of an author whose commit is approved right away', function (): void {
    config(['app.admins' => ['nunomaduro'], 'queue.default' => 'sync']);
    Queue::fake()->except(CheckUserLevel::class);
    $user = User::factory()->create(['username' => 'nunomaduro']);
    Commit::factory()->for($user)->count(3)->create();

    resolve(CreateCommit::class)->handle($user, 'Shipped the level-up notification');

    expect(levelNotifications($user))->toBe([2]);
});

it('checks the level of both the author and the stargazer when a commit is starred', function (): void {
    $author = User::factory()->create();
    Commit::factory()->for($author)->count(2)->create();
    $commit = Commit::factory()->for($author)->create();
    $stargazer = User::factory()->create();
    Commit::factory()->for($stargazer)->count(3)->create();

    Queue::fake();
    resolve(CreateCommitStar::class)->handle($stargazer, $commit);

    Queue::assertPushed(CheckUserLevel::class, 2);
    Queue::assertPushed(CheckUserLevel::class, fn (CheckUserLevel $job): bool => $job->user->is($author));
    Queue::assertPushed(CheckUserLevel::class, fn (CheckUserLevel $job): bool => $job->user->is($stargazer));
});

it('levels up the author a star pushes over the threshold', function (): void {
    config(['queue.default' => 'sync']);
    $author = User::factory()->create();
    Commit::factory()->for($author)->count(2)->create();
    $commit = Commit::factory()->for($author)->create();

    resolve(CreateCommitStar::class)->handle(User::factory()->create(), $commit);

    expect(levelNotifications($author))->toBe([2]);
});

it('does not check levels for a star on their own commit, which earns no XP', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    Queue::fake();
    resolve(CreateCommitStar::class)->handle($user, $commit);

    Queue::assertNotPushed(CheckUserLevel::class);
});
