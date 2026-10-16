<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification;

test('to array', function (): void {
    $notification = Notification::factory()->create()->refresh();

    expect(array_keys($notification->toArray()))
        ->toBe([
            'id',
            'recipient_id',
            'actor_id',
            'type',
            'commit_id',
            'read_at',
            'created_at',
            'updated_at',
            'reason',
            'subject_id',
            'conversation_id',
        ]);
});

it('belongs to a recipient and an actor', function (): void {
    $notification = Notification::factory()->create();

    expect($notification->recipient->id)->toBe($notification->recipient_id)
        ->and($notification->actor->id)->toBe($notification->actor_id)
        ->and($notification->commit)->toBeNull();
});

it('may point to a reply', function (): void {
    $notification = Notification::factory()->replied()->create();

    expect($notification->type)->toBe(NotificationType::Replied)
        ->and($notification->commit->user_id)->toBe($notification->actor_id)
        ->and($notification->commit->parent->user_id)->toBe($notification->recipient_id);
});

it('may point to a starred commit', function (): void {
    $notification = Notification::factory()->starred()->create();

    expect($notification->type)->toBe(NotificationType::Starred)
        ->and($notification->commit->user_id)->toBe($notification->recipient_id)
        ->and($notification->actor->hasStarred($notification->commit))->toBeTrue();
});
