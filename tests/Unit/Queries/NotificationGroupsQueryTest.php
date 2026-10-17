<?php

declare(strict_types=1);

use App\Actions\CreateCommitStar;
use App\Actions\CreateReplyNotifications;
use App\Actions\CreateUserFollow;
use App\Actions\DeleteCommit;
use App\Actions\DeleteUser;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Support\Facades\DB;

function starAt(User $user, Commit $commit, int $minutesAgo): void
{
    test()->travelTo(now()->subMinutes($minutesAgo), fn () => resolve(CreateCommitStar::class)->handle($user, $commit));
}

function replyAt(User $user, Commit $commit, int $minutesAgo): Commit
{
    return test()->travelTo(now()->subMinutes($minutesAgo), function () use ($user, $commit): Commit {
        $reply = Commit::factory()->for($user)->for($commit, 'parent')->create();

        resolve(CreateReplyNotifications::class)->handle($reply);

        return $reply;
    });
}

function followAt(User $follower, User $user, int $minutesAgo): void
{
    test()->travelTo(now()->subMinutes($minutesAgo), fn () => resolve(CreateUserFollow::class)->handle($follower, $user));
}

it('groups stars per commit, newest stargazer first', function (): void {
    $commit = Commit::factory()->create();
    $stargazers = User::factory()->count(5)->create();

    foreach ($stargazers as $index => $stargazer) {
        starAt($stargazer, $commit, 10 - $index);
    }

    $groups = resolve(NotificationGroupsQuery::class)->paginate($commit->user);

    $group = $groups->items()[0];

    expect($groups->items())->toHaveCount(1)
        ->and($group->type)->toBe(NotificationType::Starred)
        ->and($group->commit_id)->toBe($commit->id)
        ->and($group->actor_id)->toBe($stargazers[4]->id)
        ->and($group->actors_count)->toBe(5)
        ->and($group->actors->pluck('id')->all())->toBe([$stargazers[4]->id, $stargazers[3]->id, $stargazers[2]->id]);
});

it('groups replies per replied commit, counting each replier once', function (): void {
    $commit = Commit::factory()->create();
    $bob = User::factory()->create();
    $carol = User::factory()->create();

    replyAt($bob, $commit, 3);
    replyAt($carol, $commit, 2);
    $latest = replyAt($bob, $commit, 1);
    Notification::query()->where('commit_id', $latest->id)->update(['read_at' => now()]);

    $groups = resolve(NotificationGroupsQuery::class)->paginate($commit->user);

    $group = $groups->items()[0];

    expect($groups->items())->toHaveCount(1)
        ->and($group->type)->toBe(NotificationType::Replied)
        ->and($group->commit_id)->toBe($latest->id)
        ->and($group->subject_id)->toBe($commit->id)
        ->and($group->event_count)->toBe(3)
        ->and($group->unread_count)->toBe(2)
        ->and($group->actors_count)->toBe(2)
        ->and($group->actors->pluck('id')->all())->toBe([$bob->id, $carol->id]);
});

it('keeps stars and conversations apart while grouping followers', function (): void {
    $user = User::factory()->create();
    [$first, $second] = Commit::factory()->for($user)->count(2)->create()->all();
    $other = User::factory()->create();

    starAt($other, $first, 7);
    starAt($other, $second, 6);
    replyAt($other, $first, 5);
    $this->travelTo(now()->subMinutes(4), fn () => Notification::factory()->for($user, 'recipient')->for($other, 'actor')->mentioned()->create());
    $this->travelTo(now()->subMinutes(3), fn () => Notification::factory()->for($user, 'recipient')->for($other, 'actor')->mentioned()->create());
    followAt($other, $user, 2);
    followAt(User::factory()->create(), $user, 1);

    $groups = resolve(NotificationGroupsQuery::class)->paginate($user)->items();

    expect(array_map(fn (Notification $group): string => $group->type->value, $groups))
        ->toBe(['followed', 'mentioned', 'mentioned', 'replied', 'starred', 'starred'])
        ->and(array_map(fn (Notification $group): int => $group->actors_count, $groups))
        ->toBe([2, 1, 1, 1, 1, 1])
        ->and($groups[5]->commit_id)->toBe($first->id)
        ->and($groups[0]->actors->first()->is($groups[0]->actor))->toBeTrue();
});

it('orders groups by their newest notification', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    starAt(User::factory()->create(), $commit, 3);
    followAt(User::factory()->create(), $user, 2);
    starAt(User::factory()->create(), $commit, 1);

    $groups = resolve(NotificationGroupsQuery::class)->paginate($user)->items();

    expect(array_map(fn (Notification $group): string => $group->type->value, $groups))
        ->toBe(['starred', 'followed']);
});

it('counts the unread notifications of each group', function (): void {
    $commit = Commit::factory()->create();

    starAt(User::factory()->create(), $commit, 2);
    starAt(User::factory()->create(), $commit, 1);

    Notification::query()->latest()->firstOrFail()->forceFill(['read_at' => now()])->save();

    $group = resolve(NotificationGroupsQuery::class)->paginate($commit->user)->items()[0];

    expect($group->unread_count)->toBeOne();
});

it('shrinks groups when a stargazer is deleted', function (): void {
    $commit = Commit::factory()->create();
    $stargazers = User::factory()->count(3)->create();

    foreach ($stargazers as $index => $stargazer) {
        starAt($stargazer, $commit, 3 - $index);
    }

    resolve(DeleteUser::class)->handle($stargazers[2]);

    $group = resolve(NotificationGroupsQuery::class)->paginate($commit->user)->items()[0];

    expect($group->actor_id)->toBe($stargazers[1]->id)
        ->and($group->actors_count)->toBe(2);
});

it('drops groups when the commit is deleted', function (): void {
    $commit = Commit::factory()->create();

    starAt(User::factory()->create(), $commit, 2);
    replyAt(User::factory()->create(), $commit, 1);

    resolve(DeleteCommit::class)->handle($commit);

    expect(resolve(NotificationGroupsQuery::class)->paginate($commit->user)->items())->toBeEmpty();
});

it('paginates groups', function (): void {
    $user = User::factory()->create();
    $stargazers = User::factory()->count(2)->create();

    Commit::factory()->for($user)->count(NotificationGroupsQuery::PER_PAGE + 1)->create()
        ->each(function (Commit $commit) use ($stargazers): void {
            foreach ($stargazers as $stargazer) {
                resolve(CreateCommitStar::class)->handle($stargazer, $commit);
            }
        });

    $groups = resolve(NotificationGroupsQuery::class)->paginate($user);

    expect($groups->items())->toHaveCount(NotificationGroupsQuery::PER_PAGE)
        ->and($groups->hasMorePages())->toBeTrue();
});

it('loads any number of groups with the same number of queries', function (int $commits): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count($commits)->create()
        ->each(fn (Commit $commit) => User::factory()->count(2)->create()
            ->each(fn (User $stargazer) => resolve(CreateCommitStar::class)->handle($stargazer, $commit)));
    resolve(CreateUserFollow::class)->handle(User::factory()->create(), $user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    resolve(NotificationGroupsQuery::class)->paginate($user);

    expect(DB::getQueryLog())->toHaveCount(4);
})->with([1, 5]);

it('skips loading stacked actors when nothing is grouped', function (): void {
    $user = User::factory()->create();
    resolve(CreateUserFollow::class)->handle(User::factory()->create(), $user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $group = resolve(NotificationGroupsQuery::class)->paginate($user)->items()[0];

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($group->actors->sole()->is($group->actor))->toBeTrue();
});

it('counts the unread groups', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();

    starAt(User::factory()->create(), $commit, 2);
    starAt(User::factory()->create(), $commit, 1);
    resolve(CreateUserFollow::class)->handle(User::factory()->create(), $user);
    Notification::factory()->for($user, 'recipient')->read()->create();

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($user))->toBe(2);
});

it('loads every group refreshed since a point, including groups that began before it', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(NotificationGroupsQuery::PER_PAGE + 2)->create()
        ->each(function (Commit $commit, int $index): void {
            starAt(User::factory()->create(), $commit, 1000 + $index);
            starAt(User::factory()->create(), $commit, $index + 1);
        });

    $through = now()->subSeconds((NotificationGroupsQuery::PER_PAGE + 1) * 60 + 30)->toImmutable();
    $groups = resolve(NotificationGroupsQuery::class)->paginate($user, through: $through);

    expect($groups->items())->toHaveCount(NotificationGroupsQuery::PER_PAGE + 1)
        ->and($groups->items()[0]->event_count)->toBe(2)
        ->and($groups->hasMorePages())->toBeTrue();
});

it('counts unread groups that began before the oldest unread notification like grouping everything does', function (): void {
    $user = User::factory()->create();
    [$starred, $root] = Commit::factory()->for($user)->count(2)->create()->all();

    starAt(User::factory()->create(), $starred, 300);
    followAt(User::factory()->create(), $user, 200);
    replyAt(User::factory()->create(), $root, 190);
    Notification::query()->update(['read_at' => now()]);
    followAt(User::factory()->create(), $user, 150);
    replyAt(User::factory()->create(), $root, 140);
    starAt(User::factory()->create(), $starred, 10);
    replyAt(User::factory()->create(), $root, 5);

    $query = resolve(NotificationGroupsQuery::class);

    expect($query->unreadCount($user))->toBe(4)
        ->and($query->events($user)->where('group_rank', 1)->where('unread_count', '>', 0)->count())->toBe(4);
});

it('counts no unread groups without unread notifications', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->read()->create();

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($user))->toBe(0);
});

it('groups replies under the top of their thread when a recorded thread root is missing or a reply', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $reply = Commit::factory()->for($root, 'parent')->create();
    $nested = Commit::factory()->for($reply, 'parent')->create();
    $reply->forceFill(['root_id' => null])->save();
    $nested->forceFill(['root_id' => $reply->id])->save();

    $this->travelTo(now()->subMinutes(3), fn () => Notification::factory()->for($user, 'recipient')->for($root, 'commit')->create(['type' => NotificationType::Mentioned]));
    $this->travelTo(now()->subMinutes(2), fn () => Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create(['type' => NotificationType::Replied]));
    Notification::factory()->for($user, 'recipient')->for($nested, 'commit')->create(['type' => NotificationType::Replied]);

    $groups = resolve(NotificationGroupsQuery::class)->paginate($user)->items();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->event_count)->toBe(3);
});

it('counts no unread groups without grouping anything when everything is read', function (): void {
    $user = User::factory()->create();
    starAt(User::factory()->create(), Commit::factory()->for($user)->create(), 1);
    Notification::query()->update(['read_at' => now()]);

    DB::enableQueryLog();

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($user))->toBe(0)
        ->and(DB::getQueryLog())->toHaveCount(1);
});

it('counts the same unread groups as grouping the whole history', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    [$first, $second, $third] = User::factory()->count(3)->create()->all();

    followAt($first, $user, 300);
    followAt($second, $user, 270);
    starAt(User::factory()->create(), $commit, 180);
    followAt($third, $user, 60);
    Notification::query()->where('actor_id', $first->id)->update(['read_at' => now()]);

    $query = resolve(NotificationGroupsQuery::class);
    $wholeHistory = $query->events($user)->where('group_rank', 1)->where('unread_count', '>', 0)->count();

    expect($query->unreadCount($user))->toBe(3)->toBe($wholeHistory);
});

it('compiles the grouping window for each database dialect with bound recipient and filter values', function (string $driver, string $gap): void {
    $user = User::factory()->make(['id' => '019c1234-5678-7000-8000-123456789abc']);
    $original = DB::getDefaultConnection();
    $connection = 'notification_query_'.$driver;
    config(['database.connections.'.$connection => [
        'driver' => $driver,
        'database' => ':memory:',
        'prefix' => '',
    ]]);
    DB::setDefaultConnection($connection);

    try {
        $query = resolve(NotificationGroupsQuery::class)->events($user, 'mentions');
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        expect($sql)->toContain($gap)->not->toContain($user->id);
        expect($bindings[0])->toBe($user->id);
        expect(array_slice($bindings, -1))->toBe(['mentioned']);
        expect(mb_substr_count($sql, '?'))->toBe(count($bindings));
    } finally {
        DB::setDefaultConnection($original);
        DB::purge($connection);
    }
})->with([
    'SQLite' => ['sqlite', 'unixepoch(created_at) - unixepoch(previous_match_at)'],
    'MySQL' => ['mysql', 'TIMESTAMPDIFF(SECOND, previous_match_at, created_at)'],
    'MariaDB' => ['mariadb', 'TIMESTAMPDIFF(SECOND, previous_match_at, created_at)'],
    'PostgreSQL' => ['pgsql', 'EXTRACT(EPOCH FROM (created_at - previous_match_at))'],
]);
