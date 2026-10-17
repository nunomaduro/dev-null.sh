<?php

declare(strict_types=1);

use App\Jobs\EmbedCommit;
use App\Models\Commit;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.openai.key' => 'sk-test']);
});

it('skips commits that were deleted in the meantime', function (): void {
    Http::fake();

    $commit = Commit::factory()->create();
    $commit->delete();

    dispatch_sync(new EmbedCommit($commit));

    Http::assertNothingSent();
});

it('throws when openai is unavailable, so the job is retried', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response([], 503)]);

    $commit = Commit::factory()->create();

    expect(fn () => dispatch_sync(new EmbedCommit($commit)))->toThrow(RequestException::class)
        ->and(Commit::query()->whereKey($commit->id)->value('embedding'))->toBeNull();
});
