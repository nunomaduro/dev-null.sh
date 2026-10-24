<?php

declare(strict_types=1);

use App\Ai\Agents\TopicLabelGenerator;
use App\Enums\CommitStatus;
use App\Exceptions\CommitProcessingFailed;
use App\Jobs\ClassifyCommitTopics;
use App\Models\Commit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

it('stores a private yes or no decision without changing moderation', function (float $probability, bool $expected): void {
    TopicLabelGenerator::fake([['post_0' => []]])->preventStrayPrompts();
    Classification::fake([['post_1' => new BooleanAnswer($probability)]]);
    $commit = Commit::factory()->create(['body' => 'Will PHP 8.6 be released this November?']);

    dispatch_sync(new ClassifyCommitTopics([$commit->id]));

    expect($commit->refresh()->topic_decision)->toBe($expected)
        ->and($commit->status)->toBe(CommitStatus::Approved)
        ->and($commit->toArray())->not->toHaveKey('topic_decision');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state === ['post_1' => $commit->body]
        && $prompt->asks('post_1'));
})->with([[0.49, false], [0.5, true]]);

it('does not classify unapproved or deleted commits or completed decisions', function (array $attributes): void {
    Classification::fake();
    $commit = Commit::factory()->create($attributes);

    dispatch_sync(new ClassifyCommitTopics([$commit->id]));

    expect($commit->refresh()->topic_decision)->toBe($attributes['topic_decision'] ?? null);
    Classification::assertNothingClassified();
})->with([
    'pending' => [['status' => CommitStatus::Pending]],
    'rejected' => [['status' => CommitStatus::Rejected]],
    'deleted' => fn (): array => ['deleted_at' => now()],
    'positive decision' => [['topic_decision' => true]],
    'negative decision' => [['topic_decision' => false]],
]);

it('leaves failed classifications undecided so a retry can succeed', function (): void {
    TopicLabelGenerator::fake([['post_0' => []]])->preventStrayPrompts();
    Classification::fake(fn () => throw new RuntimeException('Classifier unavailable'));
    $commit = Commit::factory()->create();

    expect(fn () => dispatch_sync(new ClassifyCommitTopics([$commit->id])))->toThrow(function (CommitProcessingFailed $exception) use ($commit): void {
        expect($exception->getMessage())->toBe('Topic classification failed for commit '.$commit->id.' (RuntimeException, code 0).')
            ->and($exception->getPrevious())->toBeNull();
    });
    expect($commit->refresh()->topic_decision)->toBeNull();

    Classification::fake([['post_1' => new BooleanAnswer(0.9)]]);
    dispatch_sync(new ClassifyCommitTopics([$commit->id]));

    expect($commit->refresh()->topic_decision)->toBeTrue();
});

it('does not treat a missing or invalid provider answer as a negative decision', function (array $answers): void {
    config(['ai.providers.openai.key' => 'sk-test']);
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => [
        ['type' => 'predicate', 'name' => 'post_1', 'probability' => 0.9],
        ...$answers,
    ]])]);
    $commits = Commit::factory()->count(2)->create();

    expect(fn () => dispatch_sync(new ClassifyCommitTopics($commits->modelKeys())))->toThrow(CommitProcessingFailed::class);
    expect($commits->fresh()->pluck('topic_decision')->all())->toBe([null, null]);
})->with([
    'missing' => [[]],
    'out of range' => [[['type' => 'predicate', 'name' => 'post_2', 'probability' => 1.1]]],
    'wrong type' => [[['type' => 'choice', 'name' => 'post_2', 'choice' => 'yes', 'probabilities' => [['value' => 'yes', 'probability' => 0.9]]]]],
]);

it('does not restore a decision if the commit is deleted during classification', function (): void {
    TopicLabelGenerator::fake([['post_0' => []]])->preventStrayPrompts();
    $commit = Commit::factory()->create();
    Classification::fake(function () use ($commit): array {
        $commit->delete();

        return ['post_1' => new BooleanAnswer(0.9)];
    });

    dispatch_sync(new ClassifyCommitTopics([$commit->id]));

    expect(Commit::withTrashed()->findOrFail($commit->id)->topic_decision)->toBeNull();
});

it('classifies a batch in one request by answer key and skips work completed before execution', function (): void {
    TopicLabelGenerator::fake([['post_0' => []]])->preventStrayPrompts();
    config(['ai.providers.openai.key' => 'sk-test']);
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => [
        ['type' => 'predicate', 'name' => 'post_2', 'probability' => 0.1],
        ['type' => 'predicate', 'name' => 'post_1', 'probability' => 0.9],
    ]])]);
    $first = Commit::factory()->create(['body' => 'PHP release planning']);
    $second = Commit::factory()->create(['body' => 'What a beautiful day']);
    $completed = Commit::factory()->create();
    $deleted = Commit::factory()->create();
    $removed = Commit::factory()->create();
    $job = new ClassifyCommitTopics([$first->id, $second->id, $completed->id, $deleted->id, $removed->id]);
    $completed->update(['topic_decision' => false]);
    $deleted->delete();
    $removed->forceDelete();

    dispatch_sync($job);
    dispatch_sync($job);

    expect($first->refresh()->topic_decision)->toBeTrue()
        ->and($second->refresh()->topic_decision)->toBeFalse()
        ->and($completed->refresh()->topic_decision)->toBeFalse()
        ->and($deleted->refresh()->topic_decision)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => json_decode($request['input'], true) === [
        'post_1' => 'PHP release planning',
        'post_2' => 'What a beautiful day',
    ]);
});

it('does not overwrite a concurrent decision while saving the rest of a batch', function (): void {
    TopicLabelGenerator::fake([['post_0' => [], 'post_1' => []]])->preventStrayPrompts();
    $first = Commit::factory()->create();
    $second = Commit::factory()->create();
    Classification::fake(function () use ($first): array {
        $first->update(['topic_decision' => false]);

        return ['post_1' => new BooleanAnswer(0.9), 'post_2' => new BooleanAnswer(0.9)];
    });

    dispatch_sync(new ClassifyCommitTopics([$first->id, $second->id]));

    expect($first->refresh()->topic_decision)->toBeFalse()
        ->and($second->refresh()->topic_decision)->toBeTrue();
});
