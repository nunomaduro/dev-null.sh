<?php

declare(strict_types=1);

use App\Actions\CreateUserFollow;
use App\Actions\DeleteUserFollow;
use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('may follow a user', function (): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $action = resolve(CreateUserFollow::class);

    $action->handle($follower, $user);

    expect($follower->isFollowing($user))->toBeTrue()
        ->and($user->isFollowing($follower))->toBeFalse();
});

it('is idempotent', function (): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $action = resolve(CreateUserFollow::class);

    $action->handle($follower, $user);
    $action->handle($follower, $user);

    expect($follower->following()->count())->toBeOne();
});

it('keeps when an existing follow started', function (): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $this->travelTo(now()->subDay());
    resolve(CreateUserFollow::class)->handle($follower, $user);
    $this->travelBack();

    resolve(CreateUserFollow::class)->handle($follower, $user);

    expect($follower->following()->sole()->pivot->created_at->isSameDay(now()->subDay()))->toBeTrue();
});

it('follows without reading the follows the follower already has', function (): void {
    $follower = User::factory()->create();
    $follower->following()->attach(User::factory()->count(2)->create());
    $user = User::factory()->create();

    DB::enableQueryLog();

    resolve(CreateUserFollow::class)->handle($follower, $user);

    expect($follower->following()->count())->toBe(3)
        ->and(collect(DB::getQueryLog())->pluck('query')->all())
        ->each->not->toStartWith('select * from "follows"');
});

it('notifies the followed user', function (): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $action = resolve(CreateUserFollow::class);

    $action->handle($follower, $user);

    $notification = $user->notifications()->sole();

    expect($notification->type)->toBe(NotificationType::Followed)
        ->and($notification->actor_id)->toBe($follower->id)
        ->and($notification->read_at)->toBeNull();
});

it('never notifies twice about the same follower', function (): void {
    $follower = User::factory()->create();
    $user = User::factory()->create();

    $action = resolve(CreateUserFollow::class);

    $action->handle($follower, $user);
    $user->notifications()->update(['read_at' => now()]);

    resolve(DeleteUserFollow::class)->handle($follower, $user);
    $action->handle($follower, $user);
    $action->handle($follower, $user);

    expect($user->notifications()->sole()->read_at)->not->toBeNull();
});
