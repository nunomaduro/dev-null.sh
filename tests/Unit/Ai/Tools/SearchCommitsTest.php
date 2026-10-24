<?php

declare(strict_types=1);

use App\Ai\Tools\SearchCommits;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;

it('tells Tom the search is down instead of failing his reply', function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
    Exceptions::fake();
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(status: 500)]);

    expect(new SearchCommits()->handle(new ToolRequest(['query' => 'deploys'])))
        ->toBe('Search is unavailable right now. Answer without it.');

    Exceptions::assertReported(RequestException::class);
});
