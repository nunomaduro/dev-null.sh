<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\CreateWorldReply;
use App\Actions\UpdateCommitStatuses;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\ClassifyCommitTopics;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('answers an approved hello world once', function (): void {
    $commit = Commit::factory()->for(User::factory()->create(['name' => 'Nuno Maduro']))->create(['body' => 'Hello, World!']);

    $action = resolve(CreateWorldReply::class);

    $action->handle($commit);
    $action->handle($commit);

    $reply = $commit->replies()->sole();

    expect($reply->body)->toBe('Hello, Nuno Maduro.')
        ->and($reply->status)->toBe(CommitStatus::Approved)
        ->and($reply->user->username)->toBe('world')
        ->and($reply->user->github_id)->toBe('0')
        ->and($reply->root_id)->toBe($commit->id)
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
    Queue::fake([ClassifyCommitTopics::class]);

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

    resolve(CreateWorldReply::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0)
        ->and(User::query()->where('username', 'world')->exists())->toBeFalse();
});

it('does not answer itself', function (string $username): void {
    $world = User::factory()->create(['github_id' => '0', 'username' => $username]);
    $commit = Commit::factory()->for($world)->create(['body' => 'hello world']);

    resolve(CreateWorldReply::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
})->with(['world', 'devnull-world']);

it('stays quiet when the author is gone', function (): void {
    $commit = Commit::factory()->create(['body' => 'hello world', 'user_id' => null]);

    resolve(CreateWorldReply::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0)
        ->and(User::query()->where('username', 'world')->exists())->toBeFalse();
});

it('stays quiet when someone already has the name', function (): void {
    User::factory()->create(['username' => 'world']);
    $commit = Commit::factory()->create(['body' => 'hello world']);

    resolve(CreateWorldReply::class)->handle($commit);

    expect($commit->replies()->count())->toBe(0);
});

it('stays quiet once a real account named world has taken the name back', function (): void {
    $action = resolve(CreateWorldReply::class);
    $action->handle(Commit::factory()->create(['body' => 'hello world']));

    expect(User::query()->where('github_id', '0')->update(['username' => '_renamed-0']))->toBe(1);

    User::factory()->create(['username' => 'world']);

    $commit = Commit::factory()->create(['body' => 'hello world']);

    $action->handle($commit);

    expect($commit->replies()->count())->toBe(0);
});

it('keeps answering from its reserved handle without speaking as the real world', function (): void {
    $world = User::factory()->create(['github_id' => '0', 'username' => 'devnull-world']);
    $owner = User::factory()->create(['username' => 'world', 'name' => 'Real World']);
    $commit = Commit::factory()->for($owner)->create(['body' => 'hello world']);

    $action = resolve(CreateWorldReply::class);
    $action->handle($commit);
    $action->handle($commit);

    $reply = $commit->replies()->sole();
    expect($reply->user_id)->toBe($world->id)
        ->and($reply->body)->toBe('Hello, Real World.')
        ->and($owner->commits()->count())->toBe(1)
        ->and($owner->notifications()->sole()->commit_id)->toBe($reply->id);
});
