<?php

declare(strict_types=1);

use App\Models\Commit;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
});

it('returns the commits matching the query, to guests too, with what the card renders', function (): void {
    fakeSearch();

    $commit = Commit::factory()->create(['body' => 'Shipped a new feature', 'embedding' => embeddingVector(0)]);
    Commit::factory()->create(['body' => 'Something else', 'embedding' => embeddingVector(1)]);

    $response = $this->getJson('/search/commits?q=feature');

    $response->assertOk()
        ->assertJsonCount(2, 'commits')
        ->assertJsonPath('commits.0.id', $commit->id)
        ->assertJsonPath('commits.0.stars_count', 0)
        ->assertJsonPath('commits.0.starred', false)
        ->assertJsonPath('commits.0.replies_count', 0)
        ->assertJsonPath('commits.0.user.id', $commit->user->id)
        ->assertJsonStructure(['commits' => [['body_segments', 'created_at']]])
        ->assertJsonMissingPath('commits.0.embedding');
});

it('drops the candidates the decision model calls irrelevant', function (): void {
    fakeSearch([true, false]);

    $relevant = Commit::factory()->create(['body' => 'About indexing', 'embedding' => embeddingVector(0)]);
    Commit::factory()->create(['body' => 'About something else', 'embedding' => embeddingVector(1)]);

    $this->getJson('/search/commits?q=indexing')
        ->assertOk()
        ->assertJsonCount(1, 'commits')
        ->assertJsonPath('commits.0.id', $relevant->id);
});

it('returns no more than the requested limit', function (): void {
    fakeSearch();

    Commit::factory()->count(3)->create(['embedding' => embeddingVector(0)]);

    $this->getJson('/search/commits?q=feature&limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'commits');
});

it('returns nothing, and asks openai nothing, for a term shorter than three characters', function (): void {
    Http::fake();
    Commit::factory()->create(['embedding' => embeddingVector(0)]);

    $this->getJson('/search/commits?q=ab')
        ->assertOk()
        ->assertJsonCount(0, 'commits');

    Http::assertNothingSent();
});

it('rejects overly long queries and out-of-range limits', function (string $query): void {
    $this->getJson('/search/commits?'.$query)->assertUnprocessable();
})->with([
    'a query over 100 characters' => ['q='.str_repeat('a', 101)],
    'a limit of zero' => ['q=feature&limit=0'],
    'a limit over 100' => ['q=feature&limit=101'],
]);

it('asks guests to slow down after 15 searches a minute', function (): void {
    Http::fake();

    for ($attempt = 0; $attempt < 15; $attempt++) {
        $this->getJson('/search/commits?q=ab')->assertOk();
    }

    $this->getJson('/search/commits?q=ab')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertExactJson(['message' => 'Slow down: too many searches. Give it a few seconds.']);
});

it('says the search is down, rather than that nothing matched, when OpenAI cannot read the query', function (): void {
    Exceptions::fake();
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(status: 500)]);
    Commit::factory()->create(['embedding' => embeddingVector(0)]);

    $this->getJson('/search/commits?q=feature')
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Commit search is unavailable right now. Try again in a moment.']);

    Exceptions::assertReported(RequestException::class);
});
