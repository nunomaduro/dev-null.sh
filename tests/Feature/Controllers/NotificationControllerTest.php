<?php

declare(strict_types=1);

use App\Actions\CreateCommitStar;
use App\Actions\DeleteUserFollow;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\Notification;
use App\Models\User;

it('lists the notifications of the user, newest first', function (): void {
    $user = User::factory()->create();
    $followed = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subMinute()]);
    $replied = Notification::factory()->for($user, 'recipient')->replied()->create();
    Notification::factory()->create();

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notification/index')
            ->has('notifications.data', 2)
            ->where('notifications.data.0.id', $replied->id)
            ->where('notifications.data.0.type', 'replied')
            ->where('notifications.data.0.actor.id', $replied->actor_id)
            ->where('notifications.data.0.commit.id', $replied->commit_id)
            ->missing('notifications.data.0.commit.parent')
            ->where('notifications.data.1.id', $followed->id)
            ->missing('notifications.data.0.key')
            ->missing('notifications.data.0.actor.email'));
});

it('lists quotes of the users commits', function (): void {
    $user = User::factory()->create();
    $quoted = Notification::factory()->for($user, 'recipient')->quoted()->create();

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.type', 'quoted')
            ->where('notifications.data.0.actor.id', $quoted->actor_id)
            ->where('notifications.data.0.subject_id', null)
            ->where('notifications.data.0.actors_count', 1)
            ->has('notifications.data.0.actors', 1)
            ->where('notifications.data.0.actors.0.id', $quoted->actor_id)
            ->where('notifications.data.0.commit.id', $quoted->commit_id)
            ->where('notifications.data.0.commit.user_id', $quoted->actor_id));
});

it('lists mentions of the user', function (): void {
    $user = User::factory()->create();
    $mentioned = Notification::factory()->for($user, 'recipient')->mentioned()->create();

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.type', 'mentioned')
            ->where('notifications.data.0.actor.id', $mentioned->actor_id)
            ->where('notifications.data.0.commit.id', $mentioned->commit_id));
});

it('hides notifications that no longer hold', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();

    resolve(DeleteUserFollow::class)->handle($notification->actor, $user);

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertInertia(fn ($page) => $page->has('notifications.data', 0));
});

it('marks notifications read when the inbox opens and clears the badge', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('notifications.data.0.read_at', now()->startOfSecond()->toJSON())
        ->where('auth.unreadNotificationsCount', 0));

    expect($notification->refresh()->read_at)->not->toBeNull();
});

it('shares when the inbox was read so activity read on this visit stands apart from earlier visits', function (): void {
    $user = User::factory()->create();
    $earlier = Notification::factory()->for($user, 'recipient')->replied()->create(['read_at' => now()->subDay(), 'created_at' => now()->subDays(2)]);
    $latest = Notification::factory()->for($user, 'recipient')->create();

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('readAt', now()->startOfSecond()->toJSON())
        ->where('notifications.data.0.id', $latest->id)
        ->where('notifications.data.0.read_at', now()->startOfSecond()->toJSON())
        ->where('notifications.data.1.id', $earlier->id)
        ->where('notifications.data.1.read_at', now()->subDay()->startOfSecond()->toJSON()));
});

it('groups stars per commit, newest stargazer first', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $stargazers = User::factory()->count(4)->create();

    foreach ($stargazers as $index => $stargazer) {
        $this->travelTo(now()->subMinutes(4 - $index), fn () => resolve(CreateCommitStar::class)->handle($stargazer, $commit));
    }

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('notifications.data', 1)
        ->where('notifications.data.0.type', 'starred')
        ->where('notifications.data.0.actor.id', $stargazers[3]->id)
        ->where('notifications.data.0.commit.id', $commit->id)
        ->where('notifications.data.0.actors_count', 4)
        ->where('notifications.data.0.unread_count', 0)
        ->has('notifications.data.0.actors', 3)
        ->where('notifications.data.0.actors.0.id', $stargazers[3]->id)
        ->missing('notifications.data.0.actors.0.email')
        ->missing('notifications.data.0.position'));

    expect($user->notifications()->unread()->exists())->toBeFalse();
});

it('shows the images of a starred commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['body' => '']);
    $image = CommitImage::factory()->for($commit)->create();
    resolve(CreateCommitStar::class)->handle(User::factory()->create(), $commit);

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('notifications.data.0.type', 'starred')
        ->has('notifications.data.0.commit.images', 1)
        ->where('notifications.data.0.commit.images.0.url', route('commit-image.show', $image)));
});

it('shows the level reached with its rank, in all notifications only', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->leveledUp(5)->create();

    $this->actingAs($user)->get(route('notification.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notifications.data.0.type', 'leveled_up')
            ->where('notifications.data.0.level', 5)
            ->where('notifications.data.0.rank', 'wheel')
            ->where('notifications.data.0.rank_description', 'Member of the trusted wheel administrative group')
            ->where('notifications.data.0.actor.id', $user->id)
            ->where('notifications.data.0.commit', null));

    $this->get(route('notification.index', ['type' => 'stars']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('notifications.data', []));
});

it('says whether the user follows each new follower back', function (): void {
    $this->travelTo(today()->setTime(12, 0));
    $user = User::factory()->create();
    $followedBack = Notification::factory()->for($user, 'recipient')->create(['created_at' => now()->subMinute()]);
    $latest = Notification::factory()->for($user, 'recipient')->create();
    $user->following()->attach($followedBack->actor_id);

    $response = $this->actingAs($user)->get(route('notification.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.actor.is_followed', false)
            ->where('notifications.data.0.event_count', 2)
            ->where('notifications.data.0.actors_count', 2));

    $this->getJson(route('notification-group.show', $latest))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.actor.is_followed', false)
        ->assertJsonPath('data.1.actor.is_followed', true)
        ->assertJsonMissingPath('data.0.actor.email')
        ->assertJsonMissingPath('data.1.actor.email');
});

it('gives the lane of every actor so each notification takes their color', function (): void {
    $this->travelTo(today()->setTime(12, 0));
    $user = User::factory()->create();
    $earlier = Notification::factory()->for($user, 'recipient')
        ->for(User::factory()->state(['preferred_lane' => 2]), 'actor')
        ->create(['created_at' => now()->subMinute()]);
    $latest = Notification::factory()->for($user, 'recipient')
        ->for(User::factory()->state(['preferred_lane' => 5]), 'actor')
        ->create();

    $this->actingAs($user)->get(route('notification.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.actor.lane', 5)
            ->where('notifications.data.0.actors.0.lane', 5)
            ->where('notifications.data.0.actors.1.lane', 2));

    $this->getJson(route('notification-group.show', $latest))
        ->assertOk()
        ->assertJsonPath('data.0.actor_id', $latest->actor_id)
        ->assertJsonPath('data.0.actor.lane', 5)
        ->assertJsonPath('data.1.actor_id', $earlier->actor_id)
        ->assertJsonPath('data.1.actor.lane', 2);
});

it('requires authentication', function (): void {
    $response = $this->get(route('notification.index'));

    $response->assertRedirectToRoute('home');
});
