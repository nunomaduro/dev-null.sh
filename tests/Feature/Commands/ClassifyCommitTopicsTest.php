<?php

declare(strict_types=1);

use App\Ai\Agents\TopicLabelGenerator;
use App\Ai\Agents\TopicMatcher;
use App\Jobs\BackfillCommitTopics;
use App\Models\Commit;
use App\Models\Topic;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

it('backfills sequentially so later batches reuse topics and completed posts are not repeated', function (): void {
    config(['queue.default' => 'database']);
    Classification::fake([
        ['post_1' => new BooleanAnswer(0.9), 'post_2' => new BooleanAnswer(0.1)],
        ['post_1' => new BooleanAnswer(0.9), 'post_2' => new BooleanAnswer(0.9)],
    ]);
    TopicMatcher::fake([['subject_0' => [], 'subject_1' => [['topic' => 0, 'confidence' => 0.9]]]])->preventStrayPrompts();
    TopicLabelGenerator::fake([['post_0' => ['PHP release']], ['post_0' => ['Repository move']]])->preventStrayPrompts();
    $first = Commit::factory()->create(['body' => 'PHP release planning']);
    $second = Commit::factory()->create(['body' => 'What a beautiful day']);
    $quote = Commit::factory()->create(['body' => 'The repository moved', 'quoted_commit_id' => $first->id]);
    $reply = Commit::factory()->create(['parent_id' => $first->id]);
    $empty = Commit::factory()->create(['body' => '']);
    $deleted = Commit::factory()->create(['deleted_at' => now()]);
    $pending = Commit::factory()->pending()->create();
    $interesting = Commit::factory()->create(['body' => 'PHP release discussion']);
    Commit::factory()->create(['topic_decision' => false]);
    Commit::factory()->create(['topic_decision' => true]);

    $this->artisan('commits:classify-topics --batch-size=2')
        ->expectsOutputToContain('Queued topic backfill in batches of 2.')
        ->assertSuccessful();
    $later = Commit::factory()->create();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'low']);
    Classification::assertNothingClassified();

    for ($batch = 0; $batch < 5; $batch++) {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'low', '--once' => true])->assertSuccessful();
    }

    expect($first->refresh()->topic_decision)->toBeTrue()
        ->and($second->refresh()->topic_decision)->toBeFalse()
        ->and($quote->refresh()->topic_decision)->toBeTrue()
        ->and($reply->refresh()->topic_decision)->toBeNull()
        ->and($empty->refresh()->topic_decision)->toBeNull()
        ->and($deleted->refresh()->topic_decision)->toBeNull()
        ->and($pending->refresh()->topic_decision)->toBeNull();
    expect($interesting->refresh()->topic_decision)->toBeTrue()
        ->and($interesting->topics->sole()->id)->toBe($first->topics->sole()->id)
        ->and($quote->topics->sole()->value)->toBe('Repository move')
        ->and($later->refresh()->topic_decision)->toBeNull();
    $this->assertDatabaseCount('topics', 2);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);

    $later->delete();
    Topic::query()->delete();
    Classification::fake(fn () => throw new LogicException('Unexpected second classification'));
    TopicLabelGenerator::fake()->preventStrayPrompts();
    $this->artisan('commits:classify-topics --batch-size=2')
        ->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'low', '--once' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('defaults to batches of forty and allows single commit and maximum sized batches', function (array $options, int $batchSize): void {
    Queue::fake([BackfillCommitTopics::class]);
    $commits = Commit::factory()->count(21)->create();

    $this->artisan('commits:classify-topics', $options)
        ->expectsOutputToContain("Queued topic backfill in batches of {$batchSize}.")
        ->assertSuccessful();

    Queue::assertPushed(BackfillCommitTopics::class, 1);
    Queue::assertPushed(BackfillCommitTopics::class, fn (BackfillCommitTopics $job): bool => $job->batchSize === $batchSize && $job->throughId === $commits->last()->id);
})->with([
    'default' => [[], 40],
    'single' => [['--batch-size' => 1], 1],
    'maximum' => [['--batch-size' => 100], 100],
]);

it('rejects invalid batch sizes without queueing work', function (string $size): void {
    Queue::fake();

    $this->artisan('commits:classify-topics', ['--batch-size' => $size])
        ->expectsOutputToContain('The batch size must be an integer between 1 and 100.')
        ->assertExitCode(2);

    Queue::assertNothingPushed();
})->with(['0', '-1', '101', '1.5', 'many']);

it('does not enqueue a backfill for an empty database', function (): void {
    Queue::fake();

    $this->artisan('commits:classify-topics')->expectsOutputToContain('No commits to classify.')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('processes the configured batch through the whole pipeline in one queued job', function (int $size): void {
    config(['queue.default' => 'database']);
    $commits = Commit::factory()->count($size)->create();
    Classification::fake([collect(range(1, $size))->mapWithKeys(fn (int $index): array => ['post_'.$index => new BooleanAnswer(0.9)])->all()]);
    TopicLabelGenerator::fake([collect(range(0, $size - 1))->mapWithKeys(fn (int $index): array => ['post_'.$index => ['PHP release']])->all()])->preventStrayPrompts();
    TopicMatcher::fake([
        collect(range(1, $size - 1))->mapWithKeys(fn (int $index): array => ['subject_'.$index => [['topic' => 0, 'confidence' => 1]]])->all(),
    ])->preventStrayPrompts();

    $this->artisan('commits:classify-topics', ['--batch-size' => $size])->assertSuccessful();
    for ($step = 0; $step < 3; $step++) {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'low', '--once' => true])->assertSuccessful();
    }

    expect($commits->fresh()->where('topic_decision', true))->toHaveCount($size);
    $this->assertDatabaseCount('topics', 1);
    $this->assertDatabaseCount('commit_topic', $size);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    TopicLabelGenerator::assertPromptedTimes(1);
})->with([40, 41]);
