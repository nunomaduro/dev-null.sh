<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\CreateTomFriendship;
use App\Actions\CreateTomReply;
use App\Actions\UpdateCommitStatuses;
use App\Ai\Agents\Tom;
use App\Enums\CommitStatus;
use App\Jobs\ClassifyCommitTopics;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\ReplyAsTom;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['ai.providers.openai.key' => 'test', 'ai.providers.opencode.key' => 'test']);
});

it('creates one reply through the normal moderation pipeline and never replaces a deleted reply', function (): void {
    Queue::fake([ModerateCommit::class, EmbedCommit::class, ReplyAsTom::class]);
    Tom::fake(['A developer community, with rusty friends. :-D'])->preventStrayPrompts();
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $root = Commit::factory()->create();
    $commit = Commit::factory()->for($root, 'parent')->create(['body' => '@tom what is this place?', 'mentions' => ['tom' => $tom->id]]);

    resolve(CreateTomReply::class)->handle($commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);

    $reply = $commit->replies()->sole();
    expect($reply->body)->toBe('A developer community, with rusty friends. :-D')
        ->and($reply->user_id)->toBe($tom->id)
        ->and($reply->root_id)->toBe($root->id)
        ->and($reply->status)->toBe(CommitStatus::Pending);
    Queue::assertPushed(ModerateCommit::class, fn (ModerateCommit $job): bool => $job->commit->is($reply));
    Queue::assertPushed(EmbedCommit::class, fn (EmbedCommit $job): bool => $job->commit->is($reply));
    Queue::assertNotPushed(ReplyAsTom::class);
    Tom::assertPrompted('@tom what is this place?');
    Http::assertSentCount(1);

    $reply->delete();
    resolve(CreateTomReply::class)->handle($commit->id);
    expect($commit->replies()->withTrashed()->count())->toBe(1);
});

it('queues a mention only after approval and ignores code mentions', function (): void {
    Queue::fake([ReplyAsTom::class, ClassifyCommitTopics::class]);
    User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $user = User::factory()->create();
    $commit = resolve(CreateCommit::class)->handle($user, '@ToM how did this start?');
    $code = resolve(CreateCommit::class)->handle($user, 'This is code: `@tom`');

    Queue::assertNotPushed(ReplyAsTom::class);
    resolve(UpdateCommitStatuses::class)->handle([$commit->id, $code->id], CommitStatus::Approved);
    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    Queue::assertPushed(ReplyAsTom::class, 1);
    Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $commit->id);
});

it('answers direct replies without a mention only after approval', function (bool $approved, string $username): void {
    Queue::fake([ReplyAsTom::class]);
    Tom::fake(['Glad to help.']);
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => $username, 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $root = Commit::factory()->create();
    $answer = Commit::factory()->for($root, 'parent')->for($tom)->create();
    $user = User::factory()->create();
    config(['app.admins' => $approved ? [$user->username] : []]);

    $commit = resolve(CreateCommit::class)->handle($user, 'Thanks for explaining!', parent: $answer);

    if (! $approved) {
        Queue::assertNotPushed(ReplyAsTom::class);
        resolve(CreateTomReply::class)->handle($commit->id);
        Tom::assertNeverPrompted();
        resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);
    }

    Queue::assertPushed(ReplyAsTom::class, 1);
    Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);

    $reply = $commit->replies()->sole();
    expect($reply->user_id)->toBe($tom->id)
        ->and($reply->root_id)->toBe($root->id)
        ->and($reply->body)->toBe('Glad to help.');
})->with([true, false])->with(['tom', 'devnull-tom']);

it('does not answer deeper replies without a mention', function (bool $approved): void {
    Queue::fake([ReplyAsTom::class]);
    Tom::fake()->preventStrayPrompts();
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $root = Commit::factory()->for($tom)->create();
    $parent = Commit::factory()->for($root, 'parent')->create();
    $user = User::factory()->create();
    config(['app.admins' => $approved ? [$user->username] : []]);

    $commit = resolve(CreateCommit::class)->handle($user, 'I agree with you.', parent: $parent);
    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);
    resolve(CreateTomReply::class)->handle($commit->id);

    Queue::assertNotPushed(ReplyAsTom::class);
    Tom::assertNeverPrompted();
    expect($commit->replies()->count())->toBe(0);
})->with([true, false]);

it('queues Tom replies and surfaces provider failures instead of silently skipping them', function (bool $approved, string $provider): void {
    config(['ai.providers.'.$provider.'.key' => null]);
    Queue::fake([ReplyAsTom::class, ClassifyCommitTopics::class]);
    Http::fake([
        'api.openai.com/v1/decisions' => Http::response(['answers' => []]),
        'opencode.ai/zen/v1/chat/completions' => Http::response([], 401),
    ]);
    User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $user = User::factory()->create();
    config(['app.admins' => $approved ? [$user->username] : []]);

    $commit = resolve(CreateCommit::class)->handle($user, '@tom hello');
    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $commit->id);
    expect(fn () => new ReplyAsTom($commit->id)->handle(resolve(CreateTomReply::class)))
        ->toThrow(RuntimeException::class, 'Tom reply failed for commit '.$commit->id.'.');
    expect($commit->refresh()->status)->toBe(CommitStatus::Approved);
    expect($commit->replies()->count())->toBe(0);
})->with([true, false])->with(['openai', 'opencode']);

it('keeps a Tom reply reserved beyond its full provider budget and job timeout', function (): void {
    $providerBudget = collect([
        'classification' => 40,
        'model steps' => 7 * 40,
        'query embeddings' => 4 * 70,
        'relevance checks' => 2 * 60,
        'repository insights' => 2 * 10 * 5,
        'repository history' => 2 * 2 * 5,
    ])->sum();
    $queue = Queue::connection('database');
    $queue->push(new ReplyAsTom(fake()->uuid()), queue: 'tom-timeout');

    $job = $queue->pop('tom-timeout');

    expect($job)->not->toBeNull();
    expect($job->timeout())->toBeGreaterThan($providerBudget);

    foreach (['database', 'beanstalkd', 'redis'] as $connection) {
        expect(config()->integer('queue.connections.'.$connection.'.retry_after'))->toBeGreaterThan($job->timeout());
    }

    $this->travel($job->timeout())->seconds();
    expect($queue->pop('tom-timeout'))->toBeNull();

    $this->travel(config()->integer('queue.connections.database.retry_after') - $job->timeout())->seconds();
    $retry = $queue->pop('tom-timeout');
    expect($retry?->getJobId())->toBe($job->getJobId());
    expect($retry?->attempts())->toBe(2);
});

it('does not prompt for ineligible mentions', function (string $case): void {
    Tom::fake()->preventStrayPrompts();
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = Commit::factory()->create(['body' => '@tom hello', 'mentions' => ['tom' => $tom->id]]);

    match ($case) {
        'pending' => $commit->update(['status' => CommitStatus::Pending]),
        'rejected' => $commit->update(['status' => CommitStatus::Rejected]),
        'deleted' => $commit->delete(),
        'author gone' => $commit->update(['user_id' => null]),
        'self' => $commit->update(['user_id' => $tom->id]),
        'unmentioned' => $commit->update(['mentions' => []]),
        'wrong identity' => $tom->update(['github_id' => '12345']),
        'renamed' => $tom->update(['username' => 'not-tom']),
        'blocked' => $commit->user->blocking()->attach($tom),
        'blocker' => $tom->blocking()->attach($commit->user),
    };

    resolve(CreateTomReply::class)->handle($commit->id);

    Tom::assertNeverPrompted();
    Http::assertNothingSent();
    expect($commit->replies()->count())->toBe(0);
})->with(['pending', 'rejected', 'deleted', 'author gone', 'self', 'unmentioned', 'wrong identity', 'renamed', 'blocked', 'blocker']);

it('does not ask Tom to answer when the tool decision fails', function (bool $malformed): void {
    Http::fake(['api.openai.com/v1/decisions' => $malformed
        ? Http::response(['answers' => [['name' => 'SearchCommits', 'type' => 'predicate', 'probability' => [1]]]])
        : Http::response('sensitive provider response', 500)]);
    Tom::fake()->preventStrayPrompts();
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = Commit::factory()->create(['body' => '@tom hello', 'mentions' => ['tom' => $tom->id]]);

    expect(fn () => new ReplyAsTom($commit->id)->handle(resolve(CreateTomReply::class)))
        ->toThrow(RuntimeException::class, 'Tom reply failed for commit '.$commit->id.'.');

    Tom::assertNeverPrompted();
    Http::assertSent(fn (Request $request): bool => str_contains($request['input'], '@tom hello'));
    expect($commit->replies()->count())->toBe(0);
})->with(['provider error' => false, 'malformed probability' => true]);

it('discards a generated answer if its parent changes while the model is running', function (string $change): void {
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = Commit::factory()->create(['body' => '@tom hello', 'mentions' => ['tom' => $tom->id]]);
    Tom::fake(function () use ($commit, $tom, $change): string {
        match ($change) {
            'deleted' => $commit->delete(),
            'rejected' => $commit->update(['status' => CommitStatus::Rejected]),
            'blocked' => $commit->user->blocking()->attach($tom),
        };

        return 'A reply that must not be posted.';
    });

    resolve(CreateTomReply::class)->handle($commit->id);

    expect($commit->replies()->count())->toBe(0);
    Tom::assertPrompted('@tom hello');
})->with(['deleted', 'rejected', 'blocked']);

it('retries failed or blank responses without retaining provider content', function (?string $response): void {
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = Commit::factory()->create(['body' => '@tom hello', 'mentions' => ['tom' => $tom->id]]);
    Tom::fake(fn (): string => $response ?? throw new RuntimeException('sensitive provider response'));

    $job = new ReplyAsTom($commit->id);
    expect(fn () => $job->handle(resolve(CreateTomReply::class)))
        ->toThrow(RuntimeException::class, 'Tom reply failed for commit '.$commit->id.'.');
    expect($commit->replies()->count())->toBe(0);

    Tom::fake(['Back online. :-D']);
    $job->handle(resolve(CreateTomReply::class));
    expect($commit->replies()->sole()->body)->toBe('Back online. :-D');
})->with([
    'provider failure' => [null],
    'empty reply' => [''],
    'whitespace-only reply' => [" \t\n\u{00A0}"],
]);

it('queues and answers mentions of the reserved tom handle after approval', function (bool $approved): void {
    Queue::fake([ReplyAsTom::class, ClassifyCommitTopics::class]);
    Tom::fake(['Still here. :-D'])->preventStrayPrompts();
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'devnull-tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $owner = User::factory()->create(['username' => 'tom']);
    $user = User::factory()->create();
    config(['app.admins' => $approved ? [$user->username] : []]);

    $commit = resolve(CreateCommit::class)->handle($user, '@Devnull-Tom hello');
    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    Queue::assertPushed(ReplyAsTom::class, 1);
    Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);

    expect($commit->replies()->sole()->user_id)->toBe($tom->id)
        ->and($owner->commits()->count())->toBe(0);
    Tom::assertPrompted('@Devnull-Tom hello');
})->with([true, false]);

it('still answers a captured mention when tom is renamed before approval', function (): void {
    Queue::fake([ReplyAsTom::class, ClassifyCommitTopics::class]);
    Tom::fake(['I remember.'])->preventStrayPrompts();
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => []])]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), '@tom hello');
    $tom->update(['username' => 'devnull-tom']);
    User::factory()->create(['username' => 'tom']);

    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);

    Queue::assertPushed(ReplyAsTom::class, fn (ReplyAsTom $job): bool => $job->commitId === $commit->id);
    resolve(CreateTomReply::class)->handle($commit->id);
    expect($commit->replies()->sole()->user_id)->toBe($tom->id);
    Tom::assertPrompted('@tom hello');
});

it('does not queue or answer a mention of the real tom', function (bool $approved): void {
    Queue::fake([ReplyAsTom::class, ClassifyCommitTopics::class]);
    Tom::fake()->preventStrayPrompts();
    User::factory()->create(['username' => 'devnull-tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    User::factory()->create(['username' => 'tom']);
    $user = User::factory()->create();
    config(['app.admins' => $approved ? [$user->username] : []]);

    $commit = resolve(CreateCommit::class)->handle($user, '@tom hello');
    resolve(UpdateCommitStatuses::class)->handle([$commit->id], CommitStatus::Approved);
    resolve(CreateTomReply::class)->handle($commit->id);

    Queue::assertNotPushed(ReplyAsTom::class);
    Tom::assertNeverPrompted();
    expect($commit->replies()->count())->toBe(0);
})->with([true, false]);
