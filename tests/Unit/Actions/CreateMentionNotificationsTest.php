<?php

declare(strict_types=1);

use App\Actions\CreateMentionNotifications;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;

it('notifies everyone mentioned in approved commits', function (): void {
    $nuno = User::factory()->create(['username' => 'nunomaduro']);
    $taylor = User::factory()->create(['username' => 'TaylorOtwell']);
    $commit = Commit::factory()->create([
        'body' => 'Pairing with @NunoMaduro and @taylorotwell',
        'mentions' => ['nunomaduro' => $nuno->id, 'taylorotwell' => $taylor->id],
    ]);

    $action = resolve(CreateMentionNotifications::class);

    $action->handle($commit);

    foreach ([$nuno, $taylor] as $user) {
        $notification = $user->notifications()->sole();

        expect($notification->type)->toBe(NotificationType::Mentioned)
            ->and($notification->actor_id)->toBe($commit->user_id)
            ->and($notification->commit_id)->toBe($commit->id);
    }
});

it('does not notify about commits that are not approved', function (): void {
    $user = User::factory()->create(['username' => 'nunomaduro']);

    $action = resolve(CreateMentionNotifications::class);

    $action->handle(
        Commit::factory()->pending()->create(['body' => 'Hi @nunomaduro', 'mentions' => ['nunomaduro' => $user->id]]),
        Commit::factory()->rejected()->create(['body' => 'Hi @nunomaduro', 'mentions' => ['nunomaduro' => $user->id]]),
    );

    expect(Notification::query()->count())->toBe(0);
});

it('ignores usernames nobody has', function (): void {
    $action = resolve(CreateMentionNotifications::class);

    $action->handle(Commit::factory()->create(['body' => 'Hi @nobody']));

    expect(Notification::query()->count())->toBe(0);
});

it('does not send mentions without an author identity', function (): void {
    $user = User::factory()->create(['username' => 'nunomaduro']);
    $commit = Commit::factory()->create([
        'user_id' => null,
        'body' => 'Hi @nunomaduro',
        'mentions' => ['nunomaduro' => $user->id],
    ]);

    resolve(CreateMentionNotifications::class)->handle($commit);

    expect(Notification::query()->count())->toBe(0);
});

it('does not notify authors mentioning themselves', function (): void {
    $user = User::factory()->create(['username' => 'nunomaduro']);

    $action = resolve(CreateMentionNotifications::class);

    $action->handle(Commit::factory()->for($user)->create(['body' => 'Me, @nunomaduro', 'mentions' => ['nunomaduro' => $user->id]]));

    expect(Notification::query()->count())->toBe(0);
});

it('leaves the parent author of a reply to the reply notification', function (): void {
    $parent = Commit::factory()->for(User::factory()->state(['username' => 'nunomaduro']))->create();
    $other = User::factory()->create(['username' => 'taylorotwell']);
    $reply = Commit::factory()->for($parent, 'parent')->create([
        'body' => '@nunomaduro @taylorotwell look',
        'mentions' => ['nunomaduro' => $parent->user_id, 'taylorotwell' => $other->id],
    ]);

    $action = resolve(CreateMentionNotifications::class);

    $action->handle($reply);

    expect(Notification::query()->sole()->recipient_id)->toBe($other->id);
});

it('leaves the quoted author of a quote to the quote notification', function (bool $renamed): void {
    $quoted = Commit::factory()->for(User::factory()->state(['username' => 'nunomaduro']))->create();
    $other = User::factory()->create(['username' => 'taylorotwell']);
    $quote = Commit::factory()->for($quoted, 'quotedCommit')->create([
        'body' => '@nunomaduro @taylorotwell look',
        'mentions' => ['nunomaduro' => $quoted->user_id, 'taylorotwell' => $other->id],
    ]);

    if ($renamed) {
        $quoted->user->update(['username' => 'renamed']);
        User::factory()->create(['username' => 'nunomaduro']);
        $other->update(['username' => 'renamed-other']);
        User::factory()->create(['username' => 'taylorotwell']);
    }

    $action = resolve(CreateMentionNotifications::class);

    $action->handle($quote);

    expect(Notification::query()->sole()->recipient_id)->toBe($other->id);
})->with([false, true]);

it('is idempotent', function (): void {
    $user = User::factory()->create(['username' => 'nunomaduro']);
    $commit = Commit::factory()->create(['body' => 'Hi @nunomaduro', 'mentions' => ['nunomaduro' => $user->id]]);

    $action = resolve(CreateMentionNotifications::class);

    $action->handle($commit);

    $user->update(['username' => 'renamed']);
    $replacement = User::factory()->create(['username' => 'nunomaduro']);
    $action->handle($commit);

    expect(Notification::query()->count())->toBeOne();
    expect($user->notifications()->count())->toBeOne();
    expect($replacement->notifications()->count())->toBe(0);
});

it('does not notify people who muted the author or are across a block', function (string $relation, bool $mentionedActs, bool $renamed): void {
    $mentioned = User::factory()->create(['username' => 'nunomaduro']);
    $other = User::factory()->create(['username' => 'taylorotwell']);
    $silenced = Commit::factory()->create([
        'body' => 'Hi @nunomaduro',
        'mentions' => ['nunomaduro' => $mentioned->id],
    ]);
    $notified = Commit::factory()->create([
        'body' => 'Hi @nunomaduro and @taylorotwell',
        'mentions' => ['nunomaduro' => $mentioned->id, 'taylorotwell' => $other->id],
    ]);

    [$actor, $subject] = $mentionedActs ? [$mentioned, $silenced->user] : [$silenced->user, $mentioned];
    $actor->{$relation}()->attach($subject);

    if ($renamed) {
        $mentioned->update(['username' => 'renamed']);
        $replacement = User::factory()->create(['username' => 'nunomaduro']);
    }

    resolve(CreateMentionNotifications::class)->handle($silenced, $notified);

    if ($renamed) {
        expect($replacement->notifications()->count())->toBe(0);
    }

    expect($mentioned->notifications()->sole()->commit_id)->toBe($notified->id)
        ->and($other->notifications()->sole()->commit_id)->toBe($notified->id);
})->with([
    'the mentioned user muted the author' => ['muting', true],
    'the mentioned user blocked the author' => ['blocking', true],
    'the author blocked the mentioned user' => ['blocking', false],
])->with([false, true]);

it('still notifies a mentioned user when only the author muted them', function (): void {
    $mentioned = User::factory()->create(['username' => 'nunomaduro']);
    $commit = Commit::factory()->create([
        'body' => 'Hi @nunomaduro',
        'mentions' => ['nunomaduro' => $mentioned->id],
    ]);
    $commit->user->muting()->attach($mentioned);

    resolve(CreateMentionNotifications::class)->handle($commit);

    expect($mentioned->notifications()->sole()->commit_id)->toBe($commit->id);
});

it('does nothing without commits', function (): void {
    $action = resolve(CreateMentionNotifications::class);

    $action->handle();

    expect(Notification::query()->count())->toBe(0);
});
