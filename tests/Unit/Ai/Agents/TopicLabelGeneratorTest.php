<?php

declare(strict_types=1);

use App\Ai\Agents\TopicLabelGenerator;
use App\Exceptions\TopicLabelGenerationFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

it('generates short labels through DeepSeek using only the supplied text and without database writes', function (): void {
    config(['ai.providers.opencode.key' => 'test-key']);
    Http::fake(['opencode.ai/zen/v1/chat/completions' => Http::response([
        'choices' => [['finish_reason' => 'stop', 'message' => [
            'role' => 'assistant',
            'content' => '{"post_0":["eMudhra cert vulnerabilities","/dev/null livestream"]}',
        ]]],
    ])]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $body = 'Discussing eMudhra cert vulnerabilities on the /dev/null livestream.';

    $labels = resolve(TopicLabelGenerator::class)->generate($body);

    expect($labels)->toBe(['eMudhra cert vulnerabilities', '/dev/null livestream'])
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'deepseek-v4.1-flash'
        && $request['thinking'] === ['type' => 'disabled']
        && $request['response_format'] === ['type' => 'json_object']
        && collect($request['messages'])->where('role', 'user')->pluck('content')->all() === [json_encode(['post_0' => $body])]);
});

it('trims labels and removes case insensitive duplicates while preserving the first spelling', function (): void {
    TopicLabelGenerator::fake([
        ['post_0' => [' PHP 8.6 release schedule ', 'php 8.6 RELEASE schedule', '/dev/null livestream']],
    ])->preventStrayPrompts();

    expect(resolve(TopicLabelGenerator::class)->generate('PHP 8.6 release planning on the /dev/null livestream.'))
        ->toBe(['PHP 8.6 release schedule', '/dev/null livestream']);
});

it('allows no labels when the model finds no substantive topic', function (): void {
    TopicLabelGenerator::fake([['post_0' => []]])->preventStrayPrompts();

    expect(resolve(TopicLabelGenerator::class)->generate('What a beautiful day'))->toBe([]);
});

it('does not call the provider for an empty body', function (): void {
    TopicLabelGenerator::fake()->preventStrayPrompts();

    expect(resolve(TopicLabelGenerator::class)->generate(" \n\t\u{00a0}"))->toBe([]);

    TopicLabelGenerator::assertNeverPrompted();
});

it('rejects malformed label responses without exposing their content', function (string $content): void {
    config(['ai.providers.opencode.key' => 'test-key']);
    Http::fake(['opencode.ai/zen/v1/chat/completions' => Http::response([
        'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]],
    ])]);

    expect(fn () => resolve(TopicLabelGenerator::class)->generate('Sensitive post body'))->toThrow(function (TopicLabelGenerationFailed $exception): void {
        expect($exception->getMessage())->toStartWith('Topic label generation failed (')
            ->not->toContain('Sensitive post body', 'private response')
            ->and($exception->getPrevious())->toBeNull();
    });
})->with([
    'malformed JSON' => ['private response'],
    'missing labels' => ['{}'],
    'not a list' => ['{"post_0":{"label":"private response"}}'],
    'not a string' => ['{"post_0":[12]}'],
    'blank label' => ['{"post_0":["   "]}'],
    'unicode whitespace' => ['{"post_0":["\u00a0"]}'],
    'multiline label' => ['{"post_0":["private\nresponse"]}'],
    'long label' => ['{"post_0":["'.str_repeat('x', 81).'"]}'],
    'too many labels' => ['{"post_0":["one","two","three","four"]}'],
]);

it('keeps provider errors separate from an empty result and strips provider content', function (): void {
    TopicLabelGenerator::fake(fn () => throw new RuntimeException('Sensitive provider response', 429));

    expect(fn () => resolve(TopicLabelGenerator::class)->generate('A post'))->toThrow(function (TopicLabelGenerationFailed $exception): void {
        expect($exception->getMessage())->toBe('Topic label generation failed (RuntimeException, code 429).')
            ->and($exception->getPrevious())->toBeNull();
    });
});

it('generates keyed batches in one request without mixing posts or losing empty input positions', function (): void {
    TopicLabelGenerator::fake([['post_7' => ['Vite release'], 'post_2' => ['PHP release']]])->preventStrayPrompts();

    expect(resolve(TopicLabelGenerator::class)->generateBatch([2 => 'PHP 8.6', 5 => ' ', 7 => 'Vite 8']))
        ->toBe([2 => ['PHP release'], 5 => [], 7 => ['Vite release']]);

    TopicLabelGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => json_decode($prompt->prompt, true) === ['post_2' => 'PHP 8.6', 'post_7' => 'Vite 8']);
});

it('rejects an incomplete batch rather than silently dropping a post', function (): void {
    TopicLabelGenerator::fake([['post_0' => ['PHP release']]])->preventStrayPrompts();

    expect(fn () => resolve(TopicLabelGenerator::class)->generateBatch(['PHP', 'Vite']))
        ->toThrow(TopicLabelGenerationFailed::class);
});
