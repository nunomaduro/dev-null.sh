<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;

it('polls only the unread count outside the inbox without acknowledging activity', function (): void {
    $user = User::factory()->create();
    $notice = Notification::factory()->for($user, 'recipient')->create();
    $hidden = Notification::factory()->for($user, 'recipient')->replied()->create();
    $hidden->commit->update(['status' => CommitStatus::Pending]);
    Notification::factory()->create();

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'log/show',
        'X-Inertia-Partial-Data' => 'auth.unreadNotificationsCount',
    ])->get(route('log.show', 'trending'))->assertOk()
        ->assertJsonPath('props.auth.unreadNotificationsCount', 1)
        ->assertJsonMissingPath('props.auth.user')->assertJsonMissingPath('props.commits');

    expect($notice->refresh()->read_at)->toBeNull();
});

it('keeps a legacy inbox refresh query to one fixed page', function (): void {
    $user = User::factory()->create();
    $notices = collect(range(1, 62))->map(fn (int $minutes): Notification => Notification::factory()
        ->for($user, 'recipient')
        ->replied()
        ->create(['created_at' => now()->subMinutes($minutes)]));

    $this->actingAs($user)
        ->get(route('notification.index', ['through' => $notices[39]->created_at->toJSON()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', NotificationGroupsQuery::PER_PAGE)
            ->where('notifications.data.0.id', $notices[0]->id)
            ->where('notifications.next_page_url', fn (string $url): bool => ! str_contains($url, 'through')));
});

it('marks an explicit page-one inbox visit as an infinite-scroll reset', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->count(21)->replied()->create();

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'notification/index',
        'X-Inertia-Partial-Data' => 'notifications,filters,readAt,auth',
        'X-Inertia-Reset' => 'notifications',
    ])->get(route('notification.index'))->assertOk()
        ->assertJsonCount(NotificationGroupsQuery::PER_PAGE, 'props.notifications.data')
        ->assertJsonPath('scrollProps.notifications.reset', true);
});

it('forces a stale notification client to reload before it can reuse the retired poll contract', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();
    $url = route('notification.index', ['through' => $notification->created_at->toJSON()]);

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => 'stale-notification-client',
    ])->get($url)
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);
});

it('groups nested replies and mentions and bumps an old conversation using its latest event', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create(['created_at' => now()->subMonth()]);
    $first = Commit::factory()->for($root, 'parent')->create();
    $nested = Commit::factory()->for($first, 'parent')->create();
    $old = Notification::factory()->for($user, 'recipient')->for($first, 'commit')->create([
        'type' => NotificationType::Replied, 'created_at' => now()->subMinutes(50), 'read_at' => now()->subMinutes(40),
    ]);
    $other = Notification::factory()->for($user, 'recipient')->replied()->create(['created_at' => now()->subMinute()]);
    $latest = Notification::factory()->for($user, 'recipient')->for($nested, 'commit')->create([
        'type' => NotificationType::Mentioned, 'created_at' => now(),
    ]);

    $this->actingAs($user)->get(route('notification.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('notifications.data', 2)
        ->where('notifications.data.0.id', $latest->id)
        ->where('notifications.data.0.group_id', $old->id)
        ->where('notifications.data.0.event_count', 2)
        ->where('notifications.data.0.created_at', $latest->created_at->toJSON())
        ->where('notifications.data.1.id', $other->id));

    $this->getJson(route('notification-group.show', $latest))->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.1.id', $old->id)->assertJsonMissingPath('data.0.commit.parent');
});

it('orders tied timestamps by the latest notification id', function (): void {
    $user = User::factory()->create();
    $first = Notification::factory()->for($user, 'recipient')->replied()->create();
    $second = Notification::factory()->for($user, 'recipient')->replied()->create();
    $expected = collect([$first->id, $second->id])->sortDesc()->values()->all();

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->where('notifications.data.0.id', $expected[0])->where('notifications.data.1.id', $expected[1]));
});

it('groups follows across people within a rolling hour and bumps the group', function (): void {
    $user = User::factory()->create();
    $first = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subMinutes(100)]);
    Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subMinutes(50)]);
    $reply = Notification::factory()->for($user, 'recipient')->replied()->create(['created_at' => now()->subMinute()]);
    $latest = Notification::factory()->for($user, 'recipient')->create();

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->has('notifications.data', 2)->where('notifications.data.0.id', $latest->id)
        ->where('notifications.data.0.event_count', 3)->where('notifications.data.1.id', $reply->id));
    $this->getJson(route('notification-group.show', $latest))->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.2.id', $first->id);
});

it('splits follows after an interrupted gap longer than an hour regardless of filters', function (int $seconds, int $groups): void {
    $user = User::factory()->create();
    $old = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subSeconds($seconds)]);
    Notification::factory()->for($user, 'recipient')->replied()->create(['created_at' => now()->subSecond()]);
    $latest = Notification::factory()->for($user, 'recipient')->create();

    foreach (['all', 'follows'] as $type) {
        $this->actingAs($user)->get(route('notification.index', ['type' => $type]))->assertInertia(fn ($page) => $page
            ->has('notifications.data', $groups + ($type === 'all' ? 1 : 0))
            ->where('notifications.data.0.id', $latest->id)
            ->where('notifications.data.0.event_count', $groups === 1 ? 2 : 1));
    }

    $this->getJson(route('notification-group.show', [$latest, 'type' => 'follows']))
        ->assertOk()->assertJsonCount($groups === 1 ? 2 : 1, 'data');
})->with(['exactly one hour' => [3600, 1], 'one second too long' => [3601, 2], 'two days' => [172800, 2]]);

it('extends the newest follow group after days without intervening relevant activity', function (): void {
    $user = User::factory()->create();
    $old = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subDays(2)]);
    Notification::factory()->replied()->create(['created_at' => now()->subHour()]);
    $hidden = Notification::factory()->for($user, 'recipient')->replied()->create(['created_at' => now()->subMinute()]);
    $hidden->commit->delete();

    $latest = Notification::factory()->for($user, 'recipient')->create();

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->has('notifications.data', 1)->where('notifications.data.0.id', $latest->id)
        ->where('notifications.data.0.event_count', 2)->where('notifications.data.0.group_id', $old->id));
});

it('extends an old conversation only when it is still the newest group', function (bool $interrupted): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($root, 'parent')->create();
    $nested = Commit::factory()->for($parent, 'parent')->create();
    $old = Notification::factory()->for($user, 'recipient')->for($parent, 'commit')->create([
        'type' => NotificationType::Replied, 'created_at' => now()->subDays(2),
    ]);
    if ($interrupted) {
        Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subMinute()]);
    }

    $latest = Notification::factory()->for($user, 'recipient')->for($nested, 'commit')->create([
        'type' => NotificationType::Mentioned,
    ]);

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->has('notifications.data', $interrupted ? 3 : 1)->where('notifications.data.0.id', $latest->id)
        ->where('notifications.data.0.event_count', $interrupted ? 1 : 2)
        ->where('notifications.data.0.group_id', $interrupted ? $latest->id : $old->id));
    $this->get(route('notification.index', ['type' => 'replies']))->assertInertia(fn ($page) => $page
        ->has('notifications.data', 1)->where('notifications.data.0.group_id', $old->id));
    $this->getJson(route('notification-group.show', $latest))->assertOk()->assertJsonCount($interrupted ? 1 : 2, 'data');
})->with(['uninterrupted' => false, 'interrupted' => true]);

it('paginates complete groups rather than splitting a busy conversation', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    for ($index = 0; $index < 24; $index++) {
        $reply = Commit::factory()->for($root, 'parent')->create();
        Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create(['type' => NotificationType::Replied]);
    }

    Notification::factory()->for($user, 'recipient')->count(21)->replied()->create(['created_at' => now()->subDay()]);

    $first = resolve(NotificationGroupsQuery::class)->paginate($user);
    expect($first->items())->toHaveCount(20)
        ->and($first->items()[0]->event_count)->toBe(24);
    $firstIds = collect($first->items())->pluck('group_id')->all();

    $members = $this->actingAs($user)->getJson(route('notification-group.show', $first->items()[0]))
        ->assertOk()->assertJsonCount(20, 'data');
    $this->getJson(route('notification-group.show', [$first->items()[0], 'cursor' => $members->json('next_cursor')]))
        ->assertOk()->assertJsonCount(4, 'data');

    $this->actingAs($user)->get(route('notification.index', ['cursor' => $first->nextCursor()->encode()]))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 2)
            ->where('notifications.data', fn ($items): bool => collect($items)->pluck('group_id')->intersect($firstIds)->isEmpty()));
});

it('excludes hidden events and other recipients before counts previews and ordering', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $reply = Commit::factory()->for($root, 'parent')->create();
    $visible = Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create([
        'type' => NotificationType::Replied, 'created_at' => now()->subDay(),
    ]);
    $other = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subHour()]);
    foreach ([CommitStatus::Pending, CommitStatus::Rejected] as $status) {
        $hidden = Commit::factory()->for($root, 'parent')->create(['status' => $status]);
        Notification::factory()->for($user, 'recipient')->for($hidden, 'commit')->create(['type' => NotificationType::Replied]);
    }

    Notification::factory()->for($reply, 'commit')->create(['type' => NotificationType::Mentioned]);
    $deleted = Commit::factory()->for($root, 'parent')->create();
    Notification::factory()->for($user, 'recipient')->for($deleted, 'commit')->create(['type' => NotificationType::Replied]);
    $deleted->delete();

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->has('notifications.data', 2)->where('notifications.data.0.id', $other->id)
        ->where('notifications.data.1.id', $visible->id)->where('notifications.data.1.event_count', 1));
});

it('uses deleted ancestors only for group identity', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create(['body' => 'Hidden ancestor body']);
    $parent = Commit::factory()->for($root, 'parent')->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();
    $one = Notification::factory()->for($user, 'recipient')->for($parent, 'commit')->create(['type' => NotificationType::Mentioned]);
    Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create(['type' => NotificationType::Mentioned]);
    $root->delete();

    $this->actingAs($user)->get(route('notification.index'))->assertInertia(fn ($page) => $page
        ->has('notifications.data', 1)->where('notifications.data.0.group_id', $one->id)
        ->where('notifications.data.0.event_count', 2));
    $this->getJson(route('notification-group.show', $one))->assertOk()->assertDontSee('Hidden ancestor body');
});

it('filters event types while retaining all members of each group', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    foreach ([null, now()->subDay()] as $readAt) {
        $reply = Commit::factory()->for($root, 'parent')->create();
        Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create(['type' => NotificationType::Replied, 'read_at' => $readAt]);
    }

    Notification::factory()->for($user, 'recipient')->mentioned()->read()->create();
    Notification::factory()->for($user, 'recipient')->create();

    $this->actingAs($user)->get(route('notification.index', ['type' => 'replies']))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.event_count', 2)->where('filters.type', 'replies'));
    $this->get(route('notification.index', ['type' => 'mentions']))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1));
    $this->get(route('notification.index', ['type' => 'follows']))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)->where('notifications.data.0.type', 'followed'));
});

it('preserves the first read timestamp when reopening the inbox', function (): void {
    $user = User::factory()->create();
    $one = Notification::factory()->for($user, 'recipient')->create();
    $this->actingAs($user)->get(route('notification.index'))->assertOk();
    $readAt = $one->refresh()->read_at;
    expect($readAt)->not->toBeNull();
    $this->travel(5)->minutes();
    $this->get(route('notification.index'))->assertOk();
    expect($one->refresh()->read_at->equalTo($readAt))->toBeTrue();
});

it('marks all relevant activity read beyond the first page and selected type', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    $events = collect();
    for ($index = 0; $index < 24; $index++) {
        $reply = Commit::factory()->for($root, 'parent')->create();
        $events->push(Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create([
            'type' => NotificationType::Replied,
        ]));
    }

    $hiddenReply = Commit::factory()->for($root, 'parent')->create(['status' => CommitStatus::Pending]);
    $hidden = Notification::factory()->for($user, 'recipient')->for($hiddenReply, 'commit')->create(['type' => NotificationType::Replied]);
    Notification::factory()->for($user, 'recipient')->count(21)->replied()->create();
    $follow = Notification::factory()->for($user, 'recipient')->create();
    $other = Notification::factory()->replied()->create();

    $this->actingAs($user)->get(route('notification.index', ['type' => 'replies']))
        ->assertOk()->assertInertia(fn ($page) => $page->has('notifications.data', 20)
        ->where('auth.unreadNotificationsCount', 0));
    expect($user->notifications()->relevant()->unread()->count())->toBe(0)
        ->and($follow->refresh()->read_at)->not->toBeNull()
        ->and($events->last()->refresh()->read_at)->not->toBeNull()
        ->and($hidden->refresh()->read_at)->toBeNull()
        ->and($other->refresh()->read_at)->toBeNull();
});

it('leaves later arrivals unread until the inbox is visited again', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->create();
    $this->actingAs($user)->get(route('notification.index'))->assertOk();
    $this->travel(5)->minutes();
    $latest = Notification::factory()->for($user, 'recipient')->mentioned()->create();

    $this->get(route('log.show', 'trending'))->assertInertia(fn ($page) => $page
        ->where('auth.unreadNotificationsCount', 1));
    expect($latest->refresh()->read_at)->toBeNull();
    $this->get(route('notification.index', ['type' => 'follows']))->assertInertia(fn ($page) => $page
        ->where('auth.unreadNotificationsCount', 0));
    expect($latest->refresh()->read_at)->not->toBeNull();
});

it('keeps moderation decisions independent from conversation groups', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['status' => CommitStatus::Rejected]);
    Notification::factory()->for($user, 'recipient')->for($commit, 'commit')->create([
        'type' => NotificationType::Rejected, 'reason' => 'Please remove the access token.',
    ]);
    Notification::factory()->for($user, 'recipient')->replied()->create();

    $this->actingAs($user)->get(route('notification.index', ['type' => 'moderation']))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.type', 'rejected')->where('notifications.data.0.event_count', 1)
            ->where('notifications.data.0.reason', 'Please remove the access token.'));
});

it('keeps lifetime star groups separate from conversations and supports filtering and expansion', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $old = Notification::factory()->for($user, 'recipient')->starred($commit)->create([
        'created_at' => now()->subDays(2),
    ]);
    $reply = Notification::factory()->for($user, 'recipient')->replied($commit)->create([
        'created_at' => now()->subMinute(),
    ]);
    $latest = Notification::factory()->for($user, 'recipient')->starred($commit)->create();

    $this->actingAs($user)->get(route('notification.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 2)
            ->where('notifications.data.0.id', $latest->id)
            ->where('notifications.data.0.group_id', $old->id)
            ->where('notifications.data.0.event_count', 2)
            ->where('notifications.data.0.actors_count', 2)
            ->where('notifications.data.1.id', $reply->id));

    $this->get(route('notification.index', ['type' => 'stars']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('filters.type', 'stars')
            ->where('notifications.data.0.group_id', $old->id)
            ->where('auth.unreadNotificationsCount', 0));

    $this->getJson(route('notification-group.show', [$latest, 'type' => 'stars']))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.1.id', $old->id);
});

it('rejects other recipients and irrelevant notifications', function (): void {
    $user = User::factory()->create();
    $other = Notification::factory()->create();
    $hidden = Notification::factory()->for($user, 'recipient')->replied()->create();
    $hidden->commit->update(['status' => CommitStatus::Rejected]);

    foreach ([$other, $hidden] as $notification) {
        $this->actingAs($user)->getJson(route('notification-group.show', $notification))->assertNotFound();
        expect($notification->refresh()->read_at)->toBeNull();
    }
});

it('requires authentication for group history', function (): void {
    $notification = Notification::factory()->create();
    $this->get(route('notification-group.show', $notification))->assertRedirectToRoute('home');
});

it('validates notification type filters', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();

    $this->actingAs($user)->getJson(route('notification.index', ['type' => 'secret']))->assertUnprocessable()
        ->assertJsonValidationErrors('type');
    expect($notification->refresh()->read_at)->toBeNull();
    $this->getJson(route('notification-group.show', [$notification, 'type' => 'secret']))->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->get(route('notification.index', ['type' => '']))->assertOk();
});

it('includes quotes in the inbox and filters their history without reading hidden quotes', function (): void {
    $user = User::factory()->create();
    $quote = Notification::factory()->for($user, 'recipient')->quoted()->create();
    $hidden = Notification::factory()->for($user, 'recipient')->quoted()->create();
    $hidden->commit->update(['status' => CommitStatus::Pending]);
    Notification::factory()->for($user, 'recipient')->replied()->create();

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($user))->toBe(2);

    $this->actingAs($user)->get(route('notification.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications.data', 2));
    $this->get(route('notification.index', ['type' => 'quotes']))->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('filters.type', 'quotes')
            ->where('notifications.data.0.id', $quote->id)
            ->where('notifications.data.0.type', 'quoted')
            ->where('notifications.data.0.commit.id', $quote->commit_id)
            ->where('auth.unreadNotificationsCount', 0));
    $this->getJson(route('notification-group.show', [$quote, 'type' => 'quotes']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $quote->id);
    $this->getJson(route('notification-group.show', $hidden))->assertNotFound();

    expect($quote->refresh()->read_at)->not->toBeNull()
        ->and($hidden->refresh()->read_at)->toBeNull();
});

it('excludes silenced actors from groups, history and read acknowledgements', function (string $relation, bool $recipientActs): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $visible = Notification::factory()->for($user, 'recipient')->replied($root)->create();
    $hidden = Notification::factory()->for($user, 'recipient')->replied($root)->create();
    [$actor, $subject] = $recipientActs ? [$user, $hidden->actor] : [$hidden->actor, $user];
    $actor->{$relation}()->attach($subject);

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($user))->toBe(1);

    $this->actingAs($user)->get(route('notification.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.id', $visible->id)
            ->where('notifications.data.0.event_count', 1)
            ->where('notifications.data.0.actors_count', 1)
            ->where('auth.unreadNotificationsCount', 0));
    $this->getJson(route('notification-group.show', $visible))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    $this->getJson(route('notification-group.show', $hidden))->assertNotFound();

    expect($visible->refresh()->read_at)->not->toBeNull()
        ->and($hidden->refresh()->read_at)->toBeNull();
})->with([
    'recipient muted the actor' => ['muting', true],
    'recipient blocked the actor' => ['blocking', true],
    'actor blocked the recipient' => ['blocking', false],
]);

it('groups surviving activity through an authorless ancestor without exposing its content', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->create();
    $parent = Commit::factory()->for($root, 'parent')->create(['body' => 'Deleted private ancestor']);
    $first = Notification::factory()->for($user, 'recipient')->replied($parent)->create();
    $latest = Notification::factory()->for($user, 'recipient')->replied($parent)->create();

    resolve(DeleteUser::class)->handle($parent->user);

    expect($parent->withTrashed()->findOrFail($parent->id)->user_id)->toBeNull();

    $this->actingAs($user)->get(route('notification.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.id', $latest->id)
            ->where('notifications.data.0.group_id', $first->id)
            ->where('notifications.data.0.event_count', 2));
    $this->getJson(route('notification-group.show', $latest))->assertOk()
        ->assertJsonCount(2, 'data')->assertDontSee('Deleted private ancestor');
});
