<?php

declare(strict_types=1);

use App\Actions\CreateUserFollow;
use App\Actions\DeleteCommit;
use App\Actions\DeleteUser;
use App\Actions\DeleteUserFollow;
use App\Actions\UpdateCommitStatus;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Ban;
use App\Models\Notification;

it('scopes notifications to unread ones', function (): void {
    $unread = Notification::factory()->create();
    Notification::factory()->read()->create();

    expect(Notification::query()->unread()->sole()->is($unread))->toBeTrue();
});

it('keeps follows that still hold', function (): void {
    $notification = Notification::factory()->create();

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('hides follows once unfollowed, until followed again', function (): void {
    $notification = Notification::factory()->create();

    resolve(DeleteUserFollow::class)->handle($notification->actor, $notification->recipient);

    expect(Notification::query()->relevant()->exists())->toBeFalse();

    resolve(CreateUserFollow::class)->handle($notification->actor, $notification->recipient);

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('does not mistake a follow back for a follow', function (): void {
    $notification = Notification::factory()->create();

    resolve(DeleteUserFollow::class)->handle($notification->actor, $notification->recipient);
    resolve(CreateUserFollow::class)->handle($notification->recipient, $notification->actor);

    expect(Notification::query()->where('recipient_id', $notification->recipient_id)->relevant()->exists())->toBeFalse();
});

it('keeps approved replies', function (): void {
    $notification = Notification::factory()->replied()->create();

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('hides replies once rejected', function (): void {
    $notification = Notification::factory()->replied()->create();

    resolve(UpdateCommitStatus::class)->handle($notification->commit, CommitStatus::Rejected);

    expect(Notification::query()->whereKey($notification->id)->relevant()->exists())->toBeFalse();
});

it('keeps approved mentions', function (): void {
    $notification = Notification::factory()->mentioned()->create();

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('hides mentions once rejected', function (): void {
    $notification = Notification::factory()->mentioned()->create();

    resolve(UpdateCommitStatus::class)->handle($notification->commit, CommitStatus::Rejected);

    expect(Notification::query()->whereKey($notification->id)->relevant()->exists())->toBeFalse();
});

it('deletes mentions along with the commit', function (): void {
    $notification = Notification::factory()->mentioned()->create();

    resolve(DeleteCommit::class)->handle($notification->commit);

    expect(Notification::query()->exists())->toBeFalse();
});

it('deletes replies along with the reply', function (): void {
    $notification = Notification::factory()->replied()->create();

    resolve(DeleteCommit::class)->handle($notification->commit);

    expect($notification->fresh())->toBeNull();
});

it('deletes replies along with the replied commit', function (): void {
    $notification = Notification::factory()->replied()->create();

    resolve(DeleteCommit::class)->handle($notification->commit->parent);

    expect($notification->fresh())->toBeNull();
});

it('keeps approved quotes', function (): void {
    $notification = Notification::factory()->quoted()->create();

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('hides quotes once rejected', function (): void {
    $notification = Notification::factory()->quoted()->create();

    resolve(UpdateCommitStatus::class)->handle($notification->commit, CommitStatus::Rejected);

    expect(Notification::query()->whereKey($notification->id)->relevant()->exists())->toBeFalse();
});

it('deletes quotes along with the quote', function (): void {
    $notification = Notification::factory()->quoted()->create();

    resolve(DeleteCommit::class)->handle($notification->commit);

    expect($notification->fresh())->toBeNull();
});

it('deletes quotes along with the quoted commit', function (): void {
    $notification = Notification::factory()->quoted()->create();

    resolve(DeleteCommit::class)->handle($notification->commit->quotedCommit);

    expect($notification->fresh())->toBeNull();
});

it('keeps stars on approved commits', function (): void {
    $notification = Notification::factory()->starred()->create();

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
});

it('hides stars once the commit is rejected', function (): void {
    $notification = Notification::factory()->starred()->create();

    resolve(UpdateCommitStatus::class)->handle($notification->commit, CommitStatus::Rejected);

    expect(Notification::query()->where('type', NotificationType::Starred)->relevant()->exists())->toBeFalse();
});

it('deletes stars along with the starred commit', function (): void {
    $notification = Notification::factory()->starred()->create();

    resolve(DeleteCommit::class)->handle($notification->commit);

    expect($notification->fresh())->toBeNull();
});

it('deletes notifications along with the actor', function (Notification $notification): void {
    resolve(DeleteUser::class)->handle($notification->actor);

    expect($notification->fresh())->toBeNull();
})->with([
    'followed' => fn (): Notification => Notification::factory()->create(),
    'replied' => fn (): Notification => Notification::factory()->replied()->create(),
    'quoted' => fn (): Notification => Notification::factory()->quoted()->create(),
    'starred' => fn (): Notification => Notification::factory()->starred()->create(),
]);

it('deletes notifications along with the recipient', function (): void {
    $notification = Notification::factory()->create();

    resolve(DeleteUser::class)->handle($notification->recipient);

    expect($notification->fresh())->toBeNull();
});

it('hides notifications from muted actors until they are unmuted', function (Notification $notification): void {
    $notification->recipient->muting()->attach($notification->actor_id);

    expect(Notification::query()->relevant()->exists())->toBeFalse();

    $notification->recipient->muting()->detach($notification->actor_id);

    expect(Notification::query()->relevant()->sole()->is($notification))->toBeTrue();
})->with([
    'followed' => fn (): Notification => Notification::factory()->create(),
    'replied' => fn (): Notification => Notification::factory()->replied()->create(),
    'mentioned' => fn (): Notification => Notification::factory()->mentioned()->create(),
    'starred' => fn (): Notification => Notification::factory()->starred()->create(),
]);

it('hides replies, mentions and stars across a block in either direction', function (string $state, bool $recipientBlocks): void {
    $notification = Notification::factory()->{$state}()->create();

    $recipientBlocks
        ? $notification->recipient->blocking()->attach($notification->actor_id)
        : $notification->actor->blocking()->attach($notification->recipient_id);

    expect(Notification::query()->relevant()->exists())->toBeFalse();
})->with(['replied', 'mentioned', 'starred'])->with([true, false]);

it('hides what banned users did, until the ban is lifted', function (): void {
    $followed = Notification::factory()->create();
    $starred = Notification::factory()->starred()->create();

    Ban::factory()->create(['github_id' => $followed->actor->github_id]);
    Ban::factory()->create(['github_id' => $starred->actor->github_id]);

    expect(Notification::query()->relevant()->exists())->toBeFalse();

    Ban::query()->delete();

    expect(Notification::query()->relevant()->count())->toBe(2);
});
