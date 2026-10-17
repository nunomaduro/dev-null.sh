<?php

declare(strict_types=1);

use App\Actions\UpdateCommitModeration;
use App\Contracts\Moderation\AiModerationProvider;
use App\Models\Commit;
use Mockery\MockInterface;

it('persists body hash, score, and signals', function (): void {
    $commit = Commit::factory()->pending()->create([
        'body' => 'Join my telegram channel now',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    $commit->refresh();

    expect($commit->body_hash)->toBe(hash('sha256', 'join my telegram channel now'))
        ->and($commit->moderation_score)->toBeGreaterThan(0)
        ->and($commit->moderation_signals)->toBeArray()
        ->and(array_column($commit->moderation_signals, 'code'))->toContain('invite_like');
});

it('caps the score', function (): void {
    config([
        'moderation.score_cap' => 15,
        'moderation.weights.invite_like' => 30,
        'moderation.weights.has_url' => 10,
    ]);

    $commit = Commit::factory()->pending()->create([
        'body' => 'Join my discord https://example.com',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    expect($commit->refresh()->moderation_score)->toBe(15);
});

it('does not call the AI provider when AI is disabled', function (): void {
    config(['moderation.ai.enabled' => false]);

    $this->mock(AiModerationProvider::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('signals');
    });

    $commit = Commit::factory()->pending()->create([
        'body' => 'Ordinary commit body',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    expect($commit->refresh()->moderation_score)->toBe(0);
});

it('keeps deterministic scoring when AI is enabled without a provider', function (): void {
    config(['moderation.ai.enabled' => true]);

    expect(app()->bound(AiModerationProvider::class))->toBeFalse();

    $commit = Commit::factory()->pending()->create([
        'body' => 'Click the link https://example.com',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    $codes = array_column($commit->refresh()->moderation_signals ?? [], 'code');

    expect($codes)->toContain('has_url')
        ->and($codes)->toContain('invite_like');
});

it('merges AI signals when AI is enabled', function (): void {
    config(['moderation.ai.enabled' => true]);

    $this->mock(AiModerationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('signals')
            ->once()
            ->andReturn([['code' => 'ai_threat', 'weight' => 20]]);
    });

    $commit = Commit::factory()->pending()->create([
        'body' => 'Ordinary commit body',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    expect($commit->refresh()->moderation_score)->toBe(20)
        ->and(array_column($commit->moderation_signals, 'code'))->toContain('ai_threat');
});

it('keeps deterministic scoring when the AI provider throws', function (): void {
    config(['moderation.ai.enabled' => true]);

    $this->mock(AiModerationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('signals')->once()->andThrow(new RuntimeException('provider down'));
    });

    $commit = Commit::factory()->pending()->create([
        'body' => 'Click the link https://example.com',
    ]);

    resolve(UpdateCommitModeration::class)->handle($commit);

    $codes = array_column($commit->refresh()->moderation_signals ?? [], 'code');

    expect($codes)->toContain('has_url')
        ->and($codes)->toContain('invite_like')
        ->and($codes)->not->toContain('ai_threat');
});
