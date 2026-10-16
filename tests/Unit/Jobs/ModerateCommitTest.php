<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Jobs\ModerateCommit;
use App\Models\Commit;
use App\Models\CommitImage;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
});

/**
 * @param  array<string, float>  $scores
 */
function fakeClassification(array $scores = [], float $minors = 0.0): void
{
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_map(
        fn (string $key): BooleanAnswer => new BooleanAnswer($key === 'minors' ? $minors : ($scores[$key] ?? 0.0)),
        array_combine(array_keys($prompt->questions), array_keys($prompt->questions)),
    ));
}

it('stores that the ai would approve a commit it does not flag', function (): void {
    fakeClassification();

    $commit = Commit::factory()->pending()->create(['body' => 'Shipped a new feature']);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Approved)
        ->ai_categories->toBe([])
        ->status->toBe(CommitStatus::Pending);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Shipped a new feature')
        && $prompt->asks('harassment')
        && $prompt->attachments === []);
});

it('stores that the ai would reject a commit it flags, and why', function (): void {
    fakeClassification(['harassment' => 0.9, 'violence' => 0.9]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harassment', 'violence'])
        ->status->toBe(CommitStatus::Pending);
});

it('checks the images of a commit too, together with its text', function (): void {
    Storage::fake();
    Storage::put('commit-images/first.webp', 'first');
    Storage::put('commit-images/second.webp', 'second');

    fakeClassification(['sexual' => 0.9]);

    $commit = Commit::factory()->pending()->create(['body' => 'Look at this']);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp', 'position' => 0]);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/second.webp', 'position' => 1]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['sexual']);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Look at this')
        && $prompt->asks('sexual')
        && count($prompt->attachments) === 2);
});

it('flags categories scoring above the strict thresholds', function (): void {
    fakeClassification([
        'harassment' => 0.2,
        'violence' => 0.19,
        'sexual/minors' => 0.01,
    ]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harassment', 'sexual/minors']);
});

it('rejects images that may show a minor', function (): void {
    Storage::fake();
    Storage::put('commit-images/first.webp', 'first');
    Storage::put('commit-images/second.webp', 'second');

    fakeClassification(minors: 0.9);

    $commit = Commit::factory()->pending()->create();
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp', 'position' => 0]);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/second.webp', 'position' => 1]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['minor-in-image']);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('minors')
        && count($prompt->attachments) === 2);
});

it('does not ask about minors when there are no images', function (): void {
    fakeClassification(minors: 0.9);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    Classification::assertNotClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('minors'));
});

it('skips commits that were moderated in the meantime', function (): void {
    Classification::fake();

    $commit = Commit::factory()->create();

    dispatch_sync(new ModerateCommit($commit));

    Classification::assertNothingClassified();

    expect($commit->refresh()->ai_decision)->toBeNull();
});

it('skips commits that were deleted in the meantime', function (): void {
    Classification::fake();

    $commit = Commit::factory()->pending()->create();
    $commit->delete();

    dispatch_sync(new ModerateCommit($commit));

    Classification::assertNothingClassified();
});

it('throws when the ai is unavailable, so the job is retried', function (): void {
    Classification::fake(fn (): never => throw new RuntimeException('Service unavailable.'));

    $commit = Commit::factory()->pending()->create();

    expect(fn () => dispatch_sync(new ModerateCommit($commit)))->toThrow(RuntimeException::class)
        ->and($commit->refresh()->ai_decision)->toBeNull();
});
