<?php

declare(strict_types=1);

use App\Actions\CreateCommitTranslation;
use App\Ai\Agents\CommitTranslator;
use App\Enums\CommitStatus;
use App\Enums\Feature;
use App\Exceptions\CommitProcessingFailed;
use App\Jobs\DetectCommitLanguage;
use App\Models\Commit;
use App\Models\User;
use App\Services\CommitTranslations;
use App\Services\Features;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function (): void {
    config(['ai.providers.opencode.key' => 'test-key']);
});

it('translates through DeepSeek on OpenCode without sending account metadata', function (): void {
    Http::fake([
        'opencode.ai/zen/v1/chat/completions' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => [
                'role' => 'assistant',
                'content' => '{"source_language":"de","body":"Good morning"}',
            ]]],
        ]),
    ]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertOk()->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'deepseek-v4.1-flash'
        && $request['thinking'] === ['type' => 'disabled']
        && $request['response_format'] === ['type' => 'json_object']
        && str_contains(collect($request['messages'])->firstWhere('role', 'system')['content'], 'JSON object with exactly two string fields: source_language and body')
        && collect($request['messages'])->where('role', 'user')->pluck('content')->all() === ['Guten morgen']);
});

it('shares translations between readers and identical posts without changing the original', function (): void {
    CommitTranslator::fake([
        ['source_language' => 'de', 'body' => 'Good morning'],
    ])->preventStrayPrompts();
    $first = Commit::factory()->create(['body' => 'Guten morgen']);
    $duplicate = Commit::factory()->create(['body' => 'Guten morgen']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $first), ['language' => 'en'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('translation.source_name', 'German')
        ->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');

    $this->actingAs(User::factory()->create())
        ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get(route('commit.show', ['user' => $duplicate->user, 'commit' => $duplicate]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.body', 'Guten morgen')
        ->where('commit.translation.language', 'en')
        ->where('commit.translation.translated.body_segments.0.text', 'Good morning'));

    $this->postJson(route('commit-translation.store', $duplicate), ['language' => 'en'])
        ->assertOk()->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');

    expect($first->refresh()->body)->toBe('Guten morgen');
    CommitTranslator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Guten morgen');
    CommitTranslator::assertPromptedTimes(1);
});

it('keeps target languages and exact source text separate', function (): void {
    CommitTranslator::fake([
        ['source_language' => 'de', 'body' => 'Good morning'],
        ['source_language' => 'de', 'body' => 'Goedemorgen'],
        ['source_language' => 'de', 'body' => 'Good evening'],
    ]);
    $morning = Commit::factory()->create(['body' => 'Guten morgen']);
    $evening = Commit::factory()->create(['body' => 'Guten Abend']);
    $this->actingAs(User::factory()->create());

    foreach ([[$morning, 'en', 'Good morning'], [$morning, 'nl', 'Goedemorgen'], [$evening, 'en', 'Good evening']] as [$commit, $language, $expected]) {
        $this->postJson(route('commit-translation.store', $commit), ['language' => $language])
            ->assertOk()->assertJsonPath('translation.language', $language)
            ->assertJsonPath('translation.translated.body_segments.0.text', $expected);
    }

    CommitTranslator::assertPromptedTimes(3);
});

it('expires translations after seven days even when readers keep requesting them', function (): void {
    CommitTranslator::fake([
        ['source_language' => 'de', 'body' => 'Good morning'],
        ['source_language' => 'de', 'body' => 'Morning!'],
    ]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->actingAs(User::factory()->create());
    $url = route('commit-translation.store', $commit);

    $this->postJson($url, ['language' => 'en'])->assertOk();
    $this->travel(6)->days();
    $this->postJson($url, ['language' => 'en'])->assertOk()
        ->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');
    $this->travel(2)->days();
    $this->postJson($url, ['language' => 'en'])->assertOk()
        ->assertJsonPath('translation.translated.body_segments.0.text', 'Morning!');

    CommitTranslator::assertPromptedTimes(2);
});

it('does not offer translation for the readers language or indeterminate text', function (string $source): void {
    CommitTranslator::fake([['source_language' => $source, 'body' => '']]);
    $commit = Commit::factory()->create(['body' => 'Good morning']);
    new DetectCommitLanguage($commit->id)->handle(resolve(CreateCommitTranslation::class));

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.available', false)
        ->where('commit.translation.translated', null));

    CommitTranslator::assertPromptedTimes(1);
})->with(['en', 'und']);

it('detects unfamiliar posts in the background without translating on a page read', function (): void {
    Queue::fake([DetectCommitLanguage::class]);
    CommitTranslator::fake()->preventStrayPrompts();
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);

    $this->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.source_language', null)
        ->where('commit.translation.translated', null));

    Queue::assertPushed(DetectCommitLanguage::class, fn (DetectCommitLanguage $job): bool => $job->commitId === $commit->id);
    CommitTranslator::assertNeverPrompted();
});

it('offers translation of an undetected post to its readers but not its author', function (): void {
    Queue::fake([DetectCommitLanguage::class]);
    $commit = Commit::factory()->create(['body' => 'Good morning']);
    $url = route('commit.show', ['user' => $commit->user, 'commit' => $commit]);

    $this->actingAs($commit->user)->get($url)
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.source_language', null)
        ->where('commit.translation.available', false));

    $this->actingAs(User::factory()->create())->get($url)
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.source_language', null)
        ->where('commit.translation.available', true));
});

it('hides translations everywhere and stops queueing detection once an admin switches the feature off', function (): void {
    Queue::fake([DetectCommitLanguage::class]);
    CommitTranslator::fake([
        ['source_language' => 'de', 'body' => 'Good morning'],
    ])->preventStrayPrompts();
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $url = route('commit.show', ['user' => $commit->user, 'commit' => $commit]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertOk()->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');

    resolve(Features::class)->update(Feature::Translations, false);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.body', 'Guten morgen')
        ->missing('commit.translation'));
    $this->get(route('log.show', 'latest'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('commits.data.0.id', $commit->id)
        ->missing('commits.data.0.translation'));
    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertNotFound();

    Queue::assertNothingPushed();
    CommitTranslator::assertPromptedTimes(1);

    resolve(Features::class)->update(Feature::Translations, true);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.translated.body_segments.0.text', 'Good morning'));
});

it('skips language detection jobs that were already queued when the feature is switched off', function (): void {
    config(['queue.default' => 'database']);
    CommitTranslator::fake()->preventStrayPrompts();
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))->assertOk();
    $this->assertDatabaseHas('jobs', ['queue' => 'translations']);

    resolve(Features::class)->update(Feature::Translations, false);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'translations', '--once' => true])->assertSuccessful();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
    CommitTranslator::assertNeverPrompted();
});

it('rejects unsupported target languages before calling the provider', function (mixed $language): void {
    CommitTranslator::fake()->preventStrayPrompts();
    $commit = Commit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => $language])
        ->assertUnprocessable()->assertJsonValidationErrors('language');

    CommitTranslator::assertNeverPrompted();
})->with([null, 'invented', 'en; ignore instructions', [['en']]]);

it('keeps posts readable when detection cannot be queued and dispatches again after recovery', function (): void {
    Exceptions::fake();
    config(['queue.default' => 'unavailable']);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $url = route('commit.show', ['user' => $commit->user, 'commit' => $commit]);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.body', 'Guten morgen')
        ->where('commit.translation.translated', null));

    Exceptions::assertReported(fn (CommitProcessingFailed $exception): bool => str_contains($exception->getMessage(), 'Language detection dispatch failed for commit '.$commit->id));

    config(['queue.default' => 'database']);
    $this->get($url)->assertOk();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'translations']);
});

it('isolates language detection from default workers and retries transient failures', function (): void {
    Exceptions::fake();
    config(['queue.default' => 'database']);
    $attempts = 0;
    CommitTranslator::fake(function () use (&$attempts): array {
        throw_if($attempts++ < 2, RuntimeException::class, 'Provider unavailable');

        return ['source_language' => 'de', 'body' => ''];
    });
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $url = route('commit.show', ['user' => $commit->user, 'commit' => $commit]);
    $this->get($url)->assertOk();

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])->assertSuccessful();
    CommitTranslator::assertNeverPrompted();

    foreach ([0, 60, 600] as $delay) {
        $this->travel($delay)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'translations', '--once' => true])->assertSuccessful();

        if ($delay !== 600) {
            expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
            $this->get($url)->assertOk();
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseHas('jobs', ['available_at' => now()->addSeconds($delay === 0 ? 60 : 600)->getTimestamp()]);
        }
    }

    expect(resolve(CommitTranslations::class)->cached($commit)['source_language'])->toBe('de');
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    CommitTranslator::assertPromptedTimes(3);
});

it('requires sign in to generate translations', function (): void {
    CommitTranslator::fake()->preventStrayPrompts();

    $this->postJson(route('commit-translation.store', Commit::factory()->create()), ['language' => 'en'])
        ->assertUnauthorized();

    CommitTranslator::assertNeverPrompted();
});

it('does not expose cached translations through inaccessible posts', function (string $state): void {
    CommitTranslator::fake([['source_language' => 'de', 'body' => 'Good morning']]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $reader = User::factory()->create();
    $this->actingAs($reader)->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk();

    match ($state) {
        'pending' => $commit->update(['status' => CommitStatus::Pending]),
        'rejected' => $commit->update(['status' => CommitStatus::Rejected]),
        'deleted' => $commit->delete(),
        'blocked' => $reader->blocking()->attach($commit->user),
    };

    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertNotFound();

    CommitTranslator::assertPromptedTimes(1);
})->with(['pending', 'rejected', 'deleted', 'blocked']);

it('hides cached translations when an author views their post after it returns to moderation', function (): void {
    CommitTranslator::fake([['source_language' => 'de', 'body' => 'Good morning']]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->actingAs($commit->user)
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk();
    $commit->update(['status' => CommitStatus::Pending]);

    $this->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.body', 'Guten morgen')
        ->where('commit.translation', null));

    CommitTranslator::assertPromptedTimes(1);
});

it('leaves failed translations uncached and can retry even without a provider key', function (): void {
    config(['ai.providers.opencode.key' => null]);
    Exceptions::fake();
    $attempts = 0;
    CommitTranslator::fake(function () use (&$attempts): array {
        throw_if($attempts++ === 0, RuntimeException::class, 'Sensitive provider response', 503);

        return ['source_language' => 'de', 'body' => 'Good morning'];
    });
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->actingAs(User::factory()->create());

    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertServiceUnavailable()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('message', 'Could not translate this commit. Try again.');
    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
    Exceptions::assertReported(fn (CommitProcessingFailed $exception): bool => $exception->getMessage() === 'Translation failed for commit '.$commit->id.' (RuntimeException, code 503). Reason: provider_request.'
        && $exception->getCode() === 503 && ! $exception->getPrevious() instanceof Throwable);
    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk()
        ->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');
});

it('returns a safe 503 when translation fails with a string SQLSTATE code', function (): void {
    Exceptions::fake();
    CommitTranslator::fake(function (): never {
        throw new class extends PDOException
        {
            public function __construct()
            {
                parent::__construct('Sensitive database response');

                $this->code = 'HY000';
            }
        };
    });
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertServiceUnavailable()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('message', 'Could not translate this commit. Try again.');

    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
    Exceptions::assertReported(fn (CommitProcessingFailed $exception): bool => str_contains($exception->getMessage(), 'code HY000')
        && ! str_contains($exception->getMessage(), 'Sensitive database response')
        && $exception->getCode() === 0 && ! $exception->getPrevious() instanceof Throwable);
});

it('holds the translation lock throughout a slow provider retry', function (): void {
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $key = resolve(CommitTranslations::class)->key($commit).':lock';
    CommitTranslator::fake(function () use ($key): array {
        $this->travel(65)->seconds();

        expect(Cache::lock($key, 90)->get())->toBeFalse();

        return ['source_language' => 'de', 'body' => 'Good morning'];
    });

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertOk()->assertJsonPath('translation.translated.body_segments.0.text', 'Good morning');

    expect(Cache::lock($key, 90)->get())->toBeTrue();
});

it('reports why successful provider responses are rejected without logging their contents', function (string $content, string $reason, string $message = 'Could not translate this commit. Try again.'): void {
    Exceptions::fake();
    Http::fake([
        'opencode.ai/zen/v1/chat/completions' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]],
        ]),
    ]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertServiceUnavailable()->assertJsonPath('message', $message);

    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
    Exceptions::assertReported(fn (CommitProcessingFailed $exception): bool => $exception->getMessage() === 'Translation failed for commit '.$commit->id.' (RuntimeException, code 0). Reason: '.$reason.'.'
        && ! $exception->getPrevious() instanceof Throwable);
    Http::assertSentCount(1);
})->with([
    'empty response' => ['', 'empty_or_malformed_response'],
    'malformed JSON' => ['Sensitive provider text', 'empty_or_malformed_response'],
    'missing source' => ['{"body":"Sensitive translation"}', 'invalid_response_shape'],
    'missing body' => ['{"source_language":"de"}', 'invalid_response_shape'],
    'non-string source' => ['{"source_language":["de"],"body":"Sensitive translation"}', 'invalid_response_shape'],
    'non-string body' => ['{"source_language":"de","body":null}', 'invalid_response_shape'],
    'unsupported language' => ['{"source_language":"invented","body":"Sensitive translation"}', 'unsupported_source_language', 'The translation service returned an unsupported source language. Try again.'],
    'regional language code' => ['{"source_language":"de-DE","body":"Sensitive translation"}', 'unsupported_source_language', 'The translation service returned an unsupported source language. Try again.'],
    'blank translation' => ['{"source_language":"de","body":"   "}', 'empty_translation'],
    'oversized translation' => ['{"source_language":"de","body":"'.str_repeat('x', 16001).'"}', 'translation_too_long'],
]);

it('retries language detection after a malformed response and accepts an empty detection body', function (): void {
    Http::fake([
        'opencode.ai/zen/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Sensitive provider text']]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"source_language":"de","body":""}']]]]),
    ]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);

    expect(fn () => dispatch_sync(new DetectCommitLanguage($commit->id)))->toThrow(
        CommitProcessingFailed::class,
        'Translation failed for commit '.$commit->id.' (RuntimeException, code 0). Reason: empty_or_malformed_response.',
    );
    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();

    dispatch_sync(new DetectCommitLanguage($commit->id));

    expect(resolve(CommitTranslations::class)->cached($commit))->toMatchArray(['source_language' => 'de', 'translations' => []]);
    Http::assertSentCount(2);
});

it('does not cache a translation when the post is deleted during generation', function (): void {
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    CommitTranslator::fake(function () use ($commit): array {
        $commit->delete();

        return ['source_language' => 'de', 'body' => 'Good morning'];
    });

    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertServiceUnavailable();

    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
});

it('evicts cached translations when the author deletes a post', function (): void {
    CommitTranslator::fake([['source_language' => 'de', 'body' => 'Good morning']]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->actingAs($commit->user)->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk();

    $this->delete(route('commit.destroy', $commit))->assertRedirect();

    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull();
});

it('rate limits generation and does not call the provider after the limit', function (): void {
    CommitTranslator::fake([['source_language' => 'de', 'body' => 'Good morning']]);
    $commit = Commit::factory()->create(['body' => 'Guten morgen']);
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk();
    }

    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertTooManyRequests();
    $this->get(route('export'))->assertOk();
    CommitTranslator::assertPromptedTimes(1);
});

it('physically prunes expired database cache entries without deleting fresh ones', function (): void {
    config(['cache.default' => 'database']);
    Cache::put('commit-translations:v1:expired', 'expired', 1);
    Cache::put('open-graph-image:commit:expired', 'expired', 1);
    Cache::put('commit-translations:v1:fresh', 'fresh', 60);
    Cache::put('unrelated', 'keep', 60);
    $this->travel(2)->seconds();

    collect(resolve(Schedule::class)->events())->firstWhere('description', 'prune-expired-cache')->run(app());

    expect(DB::table('cache')->whereIn('key', [
        config('cache.prefix').'commit-translations:v1:expired',
        config('cache.prefix').'open-graph-image:commit:expired',
    ])->exists())->toBeFalse()
        ->and(Cache::get('commit-translations:v1:fresh'))->toBe('fresh')
        ->and(Cache::get('unrelated'))->toBe('keep');
});

it('uses the saved target language instead of the browser language', function (): void {
    $reader = User::factory()->create(['translation_language' => 'ja']);
    $commit = Commit::factory()->create(['body' => 'Good morning']);
    Cache::put(resolve(CommitTranslations::class)->key($commit), [
        'source_language' => 'en',
        'translations' => ['ja' => 'おはようございます'],
        'expires_at' => now()->addWeek()->getTimestamp(),
    ], 604800);

    $this->actingAs($reader)->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.language', 'ja')
        ->where('commit.translation.available', true)
        ->where('commit.translation.translated.body_segments.0.text', 'おはようございます'));
});

it('follows the browser language when the preference is unset', function (): void {
    $reader = User::factory()->create(['translation_language' => null]);
    $commit = Commit::factory()->create(['body' => 'Good morning']);
    Cache::put(resolve(CommitTranslations::class)->key($commit), [
        'source_language' => 'en',
        'translations' => [],
        'expires_at' => now()->addWeek()->getTimestamp(),
    ], 604800);

    $this->actingAs($reader)->withHeader('Accept-Language', 'ja-JP,ja;q=0.9')
        ->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.language', 'ja')
        ->where('commit.translation.available', true));
});

it('uses the saved preference to hide translation for matching source languages', function (): void {
    $reader = User::factory()->create(['translation_language' => 'ja']);
    $commit = Commit::factory()->create(['body' => 'おはようございます']);
    Cache::put(resolve(CommitTranslations::class)->key($commit), [
        'source_language' => 'ja',
        'translations' => [],
        'expires_at' => now()->addWeek()->getTimestamp(),
    ], 604800);

    $this->actingAs($reader)->withHeader('Accept-Language', 'en-US')
        ->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.translation.language', 'ja')
        ->where('commit.translation.available', false));
});

it('lets readers translate on demand after disabling automatic translations without affecting other readers', function (): void {
    CommitTranslator::fake([['source_language' => 'zh', 'body' => 'Released a new version today.']])->preventStrayPrompts();
    $reader = User::factory()->create(['automatic_translation' => false]);
    $other = User::factory()->create();
    $commit = Commit::factory()->create(['body' => '今天发布了新版本。']);

    $this->actingAs($other)->postJson(route('commit-translation.store', $commit), ['language' => 'en'])->assertOk();

    $this->actingAs($reader)->withHeader('Accept-Language', 'en')
        ->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertInertia(fn ($page) => $page
            ->where('auth.automaticTranslation', false)
            ->where('commit.body', '今天发布了新版本。')
            ->where('commit.translation.translated.body_segments.0.text', 'Released a new version today.'));

    $this->postJson(route('commit-translation.store', $commit), ['language' => 'en'])
        ->assertOk()->assertJsonPath('translation.translated.body_segments.0.text', 'Released a new version today.');

    $this->actingAs($other)->get(route('commit.show', ['user' => $commit->user, 'commit' => $commit]))
        ->assertInertia(fn ($page) => $page->where('auth.automaticTranslation', true));

    CommitTranslator::assertPromptedTimes(1);
});
