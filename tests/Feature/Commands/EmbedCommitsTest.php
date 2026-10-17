<?php

declare(strict_types=1);

use App\Models\Commit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.openai.key' => 'sk-test']);
});

it('embeds every commit missing an embedding, in one request', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(1), embeddingVector(2)]))]);

    $first = Commit::factory()->create(['body' => 'First commit']);
    $second = Commit::factory()->create(['body' => 'Second commit']);

    $this->artisan('commits:embed')
        ->expectsOutputToContain('Embedded 2 commits.')
        ->assertSuccessful();

    expect(Commit::query()->whereKey($first->id)->value('embedding'))->toBe(embeddingVector(1))
        ->and(Commit::query()->whereKey($second->id)->value('embedding'))->toBe(embeddingVector(2));

    Http::assertSent(fn (Request $request): bool => $request['input'] === ['First commit', 'Second commit']);
    Http::assertSentCount(1);
});

it('leaves commits that already have an embedding alone', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(4)]))]);

    Commit::factory()->create(['embedding' => embeddingVector(9)]);
    $missing = Commit::factory()->create(['body' => 'Needs one']);

    $this->artisan('commits:embed')
        ->expectsOutputToContain('Embedded 1 commits.')
        ->assertSuccessful();

    expect(Commit::query()->whereKey($missing->id)->value('embedding'))->toBe(embeddingVector(4));

    Http::assertSent(fn (Request $request): bool => $request['input'] === ['Needs one']);
    Http::assertSentCount(1);
});

it('leaves commits without a body alone', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(4)]))]);

    $imageOnly = Commit::factory()->create(['body' => '']);
    Commit::factory()->create(['body' => 'Needs one']);

    $this->artisan('commits:embed --all')
        ->expectsOutputToContain('Embedded 1 commits.')
        ->assertSuccessful();

    expect($imageOnly->refresh()->embedding)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request['input'] === ['Needs one']);
});

it('embeds every commit again with --all', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(4)]))]);

    $commit = Commit::factory()->create(['embedding' => embeddingVector(9)]);

    $this->artisan('commits:embed --all')
        ->expectsOutputToContain('Embedded 1 commits.')
        ->assertSuccessful();

    expect(Commit::query()->whereKey($commit->id)->value('embedding'))->toBe(embeddingVector(4));
});

it('leaves deleted commits alone', function (): void {
    Http::fake();

    Commit::factory()->create()->delete();

    $this->artisan('commits:embed')
        ->expectsOutputToContain('Embedded 0 commits.')
        ->assertSuccessful();

    Http::assertNothingSent();
});
