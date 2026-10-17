<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\ModerateCommit;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\Notification;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.openai.key' => 'sk-test']);
});

/**
 * @param  list<string>  $flagged
 * @param  array<string, float>  $scores
 * @return array{results: list<array{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>}>}
 */
function moderationResponse(array $flagged = [], array $scores = []): array
{
    $categories = ['harassment', 'violence', 'sexual', 'sexual/minors'];

    return ['results' => [[
        'flagged' => $flagged !== [],
        'categories' => array_combine($categories, array_map(fn (string $category): bool => in_array($category, $flagged, true), $categories)),
        'category_scores' => array_combine($categories, array_map(fn (string $category): float => $scores[$category] ?? (in_array($category, $flagged, true) ? 0.9 : 0.0), $categories)),
    ]]];
}

/**
 * @return array{status: string, output: list<array{type: string, role: string, content: list<array<string, string>>}>}
 */
function reviewResponse(string $question, bool|string $answer): array
{
    $content = is_bool($answer)
        ? ['type' => 'output_text', 'text' => json_encode([$question => $answer])]
        : ['type' => 'refusal', 'refusal' => $answer];

    return ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [$content]]]];
}

/**
 * Answers each question the reasoning model is asked with the given response, and a clear no otherwise.
 *
 * @param  array<string, array<string, mixed>>  $responses
 * @return Closure(Request): PromiseInterface
 */
function reviewResponses(array $responses = []): Closure
{
    return function (Request $request) use ($responses): PromiseInterface {
        $question = $request['text']['format']['name'];

        return Http::response($responses[$question] ?? reviewResponse($question, false));
    };
}

dataset('unclear answers', [
    'a refusal' => [reviewResponse('any', "I'm sorry, I cannot assist with that request.")],
    'unreadable text' => [['status' => 'incomplete', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '{"min']]]]]],
    'no output' => [['status' => 'completed', 'output' => []]],
]);

it('stores that the ai would approve a commit it does not flag', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->create(['body' => 'Shipped a new feature']);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Approved)
        ->ai_categories->toBe([])
        ->status->toBe(CommitStatus::Pending);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'omni-moderation-latest'
        && $request['input'] === [['type' => 'text', 'text' => 'Shipped a new feature']]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/responses')
        && $request['model'] === 'gpt-6-astra'
        && $request['text']['format']['name'] === 'harmful'
        && $request['input'][1]['content'] === [['type' => 'input_text', 'text' => 'Shipped a new feature']]);

    Http::assertSentCount(2);
});

it('auto-approves clean commits only for authors with an approved commit', function (CommitStatus $previousStatus, bool $sameAuthor, bool $deleted, bool $anonymous, CommitStatus $expected): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $previous = Commit::factory()->create(['status' => $previousStatus]);

    if ($deleted) {
        $previous->delete();
    }

    $attributes = $sameAuthor ? ['user_id' => $previous->user_id] : [];

    if ($anonymous) {
        $attributes['user_id'] = null;
        $previous->update(['user_id' => null]);
    }

    $commit = Commit::factory()->pending()->create($attributes);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Approved)
        ->ai_categories->toBe([])
        ->status->toBe($expected);
})->with([
    'approved history' => [CommitStatus::Approved, true, false, false, CommitStatus::Approved],
    'pending history' => [CommitStatus::Pending, true, false, false, CommitStatus::Pending],
    'rejected history' => [CommitStatus::Rejected, true, false, false, CommitStatus::Pending],
    'another author' => [CommitStatus::Approved, false, false, false, CommitStatus::Pending],
    'deleted history' => [CommitStatus::Approved, true, true, false, CommitStatus::Pending],
    'anonymous author' => [CommitStatus::Approved, true, false, true, CommitStatus::Pending],
]);

it('keeps flagged commits pending even when the author has an approved commit', function (bool $harmful): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response($harmful ? moderationResponse() : moderationResponse(['harassment'])),
        'api.openai.com/v1/responses' => reviewResponses(['harmful' => reviewResponse('harmful', $harmful)]),
    ]);

    $previous = Commit::factory()->create();
    $commit = Commit::factory()->pending()->create(['user_id' => $previous->user_id]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->status->toBe(CommitStatus::Pending);
})->with([true, false]);

it('stores that the ai would reject a commit it flags, and why', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse(['harassment', 'violence'])),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harassment', 'violence'])
        ->status->toBe(CommitStatus::Pending);
});

it('rejects a commit the ai is all but certain about, without a reason', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse(['harassment'], ['harassment' => 0.95])),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harassment'])
        ->status->toBe(CommitStatus::Rejected);

    $notification = Notification::query()->where('commit_id', $commit->id)->sole();

    expect($notification)
        ->type->toBe(NotificationType::Rejected)
        ->reason->toBeNull();
});

it('leaves a commit the ai is not all but certain about to moderators', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse(['harassment'], ['harassment' => 0.94])),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->status->toBe(CommitStatus::Pending);
});

it('checks the images of a commit too, one per request', function (): void {
    Storage::fake();
    Storage::put('commit-images/first.webp', 'first');
    Storage::put('commit-images/second.webp', 'second');

    Http::fakeSequence('api.openai.com/v1/moderations')
        ->push(moderationResponse())
        ->push(moderationResponse(['sexual']));
    Http::fake(['api.openai.com/v1/responses' => reviewResponses()]);

    $commit = Commit::factory()->pending()->create(['body' => 'Look at this']);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp', 'position' => 0]);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/second.webp', 'position' => 1]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['sexual']);

    $inputs = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/moderations'))
        ->map(fn (array $pair): mixed => $pair[0]['input'])
        ->all();

    expect($inputs)->toBe([
        [
            ['type' => 'text', 'text' => 'Look at this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,'.base64_encode('first')]],
        ],
        [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,'.base64_encode('second')]],
        ],
    ]);
});

it('checks only the images of a commit without a body', function (): void {
    Storage::fake();
    Storage::put('commit-images/first.webp', 'first');

    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->create(['body' => '']);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp', 'position' => 0]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Approved)
        ->ai_categories->toBe([]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/moderations')
        && $request['input'] === [['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,'.base64_encode('first')]]]);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/responses')
        && $request['text']['format']['name'] === 'harmful');
});

it('flags categories scoring above the strict thresholds, even when openai does not flag them', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse(scores: [
            'harassment' => 0.2,
            'violence' => 0.19,
            'sexual/minors' => 0.01,
        ])),
        'api.openai.com/v1/responses' => reviewResponses(),
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

    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(['minors' => reviewResponse('minors', true)]),
    ]);

    $commit = Commit::factory()->pending()->create();
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp', 'position' => 0]);
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/second.webp', 'position' => 1]);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['minor-in-image'])
        ->status->toBe(CommitStatus::Pending);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/responses')
        && $request['model'] === 'gpt-6-astra'
        && $request['text']['format']['name'] === 'minors'
        && $request['input'][1]['content'] === [
            ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,'.base64_encode('first')],
            ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,'.base64_encode('second')],
        ]);
});

it('rejects images when the reasoning model gives no clear answer', function (array $response): void {
    Storage::fake();
    Storage::put('commit-images/first.webp', 'first');

    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(['minors' => $response]),
    ]);

    $commit = Commit::factory()->pending()->create();
    CommitImage::factory()->for($commit)->create(['path' => 'commit-images/first.webp']);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['minor-in-image']);
})->with('unclear answers');

it('flags text the moderation model misses, such as an implied threat, without rejecting it', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(['harmful' => reviewResponse('harmful', true)]),
    ]);

    $commit = Commit::factory()->pending()->create(['body' => 'I hate joomla and i have a bomb']);

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harmful-text'])
        ->status->toBe(CommitStatus::Pending);
});

it('flags text when the reasoning model gives no clear answer', function (array $response): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(['harmful' => $response]),
    ]);

    $commit = Commit::factory()->pending()->create();

    dispatch_sync(new ModerateCommit($commit));

    expect($commit->refresh())
        ->ai_decision->toBe(CommitStatus::Rejected)
        ->ai_categories->toBe(['harmful-text']);
})->with('unclear answers');

it('skips commits that were moderated in the meantime', function (): void {
    Http::fake();

    $commit = Commit::factory()->create();

    dispatch_sync(new ModerateCommit($commit));

    Http::assertNothingSent();

    expect($commit->refresh()->ai_decision)->toBeNull();
});

it('skips commits that were deleted in the meantime', function (): void {
    Http::fake();

    $commit = Commit::factory()->pending()->create();
    $commit->delete();

    dispatch_sync(new ModerateCommit($commit));

    Http::assertNothingSent();
});

it('throws when the ai is unavailable, so the job is retried', function (): void {
    Http::fake(['api.openai.com/v1/moderations' => Http::response([], 503)]);

    $commit = Commit::factory()->pending()->create();

    expect(fn () => dispatch_sync(new ModerateCommit($commit)))->toThrow(RequestException::class)
        ->and($commit->refresh()->ai_decision)->toBeNull();
});

it('moderates an attached gif by its giphy url', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response(moderationResponse()),
        'api.openai.com/v1/responses' => reviewResponses(),
    ]);

    $commit = Commit::factory()->pending()->withGiphy()->create();

    dispatch_sync(new ModerateCommit($commit));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'moderations')
        && str_contains($request->body(), 'media2.giphy.com'));

    // The minors check runs because the commit has an image URL (the gif):
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'responses')
        && str_contains($request->body(), 'media2.giphy.com'));
});
