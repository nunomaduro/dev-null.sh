<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\CreateEasterEggReplies;
use App\Actions\UpdateCommitStatuses;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\User;

it('answers an approved hello world once', function (): void {
    $commit = Commit::factory()->for(User::factory()->create(['name' => 'Nuno Maduro']))->create(['body' => 'Hello, World!']);

    $action = resolve(CreateEasterEggReplies::class);

    $action->handle($commit);
    $action->handle($commit);

    $reply = $commit->replies()->sole();

    expect($reply->body)->toBe('Hello, Nuno Maduro.')
        ->and($reply->status)->toBe(CommitStatus::Approved)
        ->and($reply->user->username)->toBe('world')
        ->and($reply->user->github_id)->toBe('0')
        ->and($commit->user->notifications()->sole()->commit_id)->toBe($reply->id);
});

it('answers when an admin commits hello world', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $commit = resolve(CreateCommit::class)->handle(
        User::factory()->create(['username' => 'nunomaduro', 'name' => 'Nuno Maduro']),
        'hello world',
    );

    expect($commit->replies()->sole()->body)->toBe('Hello, Nuno Maduro.');
});

it('waits until a hello world is approved', function (): void {
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(['name' => 'Taylor Otwell']), 'hello world');

    expect($commit->status)->toBe(CommitStatus::Pending)
        ->and($commit->replies()->count())->toBe(0);

    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    $reply = $commit->replies()->sole();

    expect($reply->body)->toBe('Hello, Taylor Otwell.')
        ->and($commit->user->notifications()->where('type', NotificationType::Replied)->sole()->commit_id)->toBe($reply->id);
});

it('stays quiet for anything else', function (): void {
    $commit = Commit::factory()->create(['body' => 'hello world, we shipped']);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0)
        ->and(User::query()->where('username', 'world')->exists())->toBeFalse();
});

it('does not answer itself', function (string $body, string $githubId, string $username): void {
    $bot = User::factory()->create(['github_id' => $githubId, 'username' => $username]);
    $commit = Commit::factory()->for($bot)->create(['body' => $body]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with([
    ['hello world', '0', 'world'],
    ['php artisan migrate:fresh', 'bot:artisan', 'artisan'],
]);

it('stays quiet when the author is gone', function (string $body, string $username): void {
    $commit = Commit::factory()->create(['body' => $body, 'user_id' => null]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0)
        ->and(User::query()->where('username', $username)->exists())->toBeFalse();
})->with([
    ['hello world', 'world'],
    ['php artisan migrate:fresh', 'artisan'],
]);

it('stays quiet when someone already has the name', function (string $body, string $username): void {
    User::factory()->create(['username' => ucfirst($username)]);
    $commit = Commit::factory()->create(['body' => $body]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with([
    ['hello world', 'world'],
    ['php artisan migrate:fresh', 'artisan'],
]);

it('answers migrate fresh with a nested joke once', function (string $body): void {
    $commit = Commit::factory()->create(['body' => $body]);
    $action = resolve(CreateEasterEggReplies::class);

    $action->handle($commit);
    $action->handle($commit);

    $reply = $commit->replies()->sole();
    $followUp = $reply->replies()->sole();

    expect($reply->body)->toBe('Deleting database.')
        ->and($reply->status)->toBe(CommitStatus::Approved)
        ->and($reply->root_id)->toBe($commit->id)
        ->and($reply->user->username)->toBe('artisan')
        ->and($reply->user->github_id)->toBe('bot:artisan')
        ->and($followUp->body)->toBe('Just kidding.')
        ->and($followUp->status)->toBe(CommitStatus::Approved)
        ->and($followUp->root_id)->toBe($commit->id)
        ->and($followUp->user_id)->toBe($reply->user_id)
        ->and($followUp->replies()->count())->toBe(0)
        ->and($commit->user->notifications()->sole()->commit_id)->toBe($reply->id)
        ->and($reply->user->notifications()->count())->toBe(0);
})->with(['php artisan migrate:fresh', " \n`php artisan migrate:fresh`\n "]);

it('waits for approval before answering migrate fresh', function (): void {
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'php artisan migrate:fresh');

    expect($commit->status)->toBe(CommitStatus::Pending)
        ->and($commit->replies()->count())->toBe(0);

    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    $reply = $commit->replies()->sole();

    expect($reply->body)->toBe('Deleting database.')
        ->and($reply->replies()->sole()->body)->toBe('Just kidding.');
});

it('answers migrate fresh immediately for an admin', function (): void {
    config(['app.admins' => ['nunomaduro']]);

    $commit = resolve(CreateCommit::class)->handle(
        User::factory()->create(['username' => 'nunomaduro']),
        'php artisan migrate:fresh',
    );

    expect($commit->replies()->sole()->replies()->sole()->body)->toBe('Just kidding.');
});

it('keeps the thread root when migrate fresh is a reply', function (): void {
    $root = Commit::factory()->create();
    $commit = Commit::factory()->create([
        'body' => 'php artisan migrate:fresh',
        'parent_id' => $root->id,
        'root_id' => $root->id,
    ]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    $reply = $commit->replies()->sole();

    expect($reply->root_id)->toBe($root->id)
        ->and($reply->replies()->sole()->root_id)->toBe($root->id);
});

it('does not answer an unapproved migrate fresh', function (CommitStatus $status): void {
    $commit = Commit::factory()->create(['body' => 'php artisan migrate:fresh', 'status' => $status]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with([CommitStatus::Pending, CommitStatus::Rejected]);

it('does not answer other commands or mentions of migrate fresh', function (string $body): void {
    $commit = Commit::factory()->create(['body' => $body]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with([
    'php artisan migrate',
    'php artisan migrate:fresh --seed',
    'Try php artisan migrate:fresh',
]);

it('uses a separate bot for each easter egg', function (): void {
    $action = resolve(CreateEasterEggReplies::class);
    $helloWorld = Commit::factory()->create(['body' => 'hello world']);
    $migrateFresh = Commit::factory()->create(['body' => 'php artisan migrate:fresh']);

    $action->handle($helloWorld);
    $action->handle($migrateFresh);

    expect($helloWorld->replies()->sole()->user->username)->toBe('world')
        ->and($migrateFresh->replies()->sole()->user->username)->toBe('artisan');
});

it('stays quiet once a real account took the name from the bot', function (string $body, string $githubId): void {
    User::factory()->create(['github_id' => $githubId, 'username' => "_renamed-{$githubId}"]);
    $commit = Commit::factory()->create(['body' => $body]);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with([
    ['hello world', '0'],
    ['php artisan migrate:fresh', 'bot:artisan'],
]);

it('keeps the name placeholder out of the author name', function (): void {
    $commit = Commit::factory()->for(User::factory()->create(['name' => 'Jane :name Doe']))->create(['body' => 'hello world']);

    resolve(CreateEasterEggReplies::class)->handle($commit);

    expect($commit->replies()->sole()->body)->toBe('Hello, Jane :name Doe.');
});

it('answers only once when a commit is rejected and approved again', function (): void {
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'php artisan migrate:fresh');
    $statuses = resolve(UpdateCommitStatuses::class);

    $statuses->handle([$commit->id], CommitStatus::Approved);
    $statuses->handle([$commit->id], CommitStatus::Rejected);
    $statuses->handle([$commit->id], CommitStatus::Approved);

    expect($commit->replies()->sole()->replies()->count())->toBe(1);
});

it('stays quiet once a real account named world has taken the name back', function (): void {
    $action = resolve(CreateEasterEggReplies::class);
    $action->handle(Commit::factory()->create(['body' => 'hello world']));

    expect(User::query()->where('github_id', '0')->update(['username' => '_renamed-0']))->toBe(1);

    User::factory()->create(['username' => 'world']);

    $commit = Commit::factory()->create(['body' => 'hello world']);

    $action->handle($commit);

    expect($commit->replies()->count())->toBe(0);
});
