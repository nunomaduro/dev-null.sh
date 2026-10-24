<?php

declare(strict_types=1);

use App\Actions\CreateTomFriendship;
use App\Actions\CreateTomReply;
use App\Ai\Agents\Tom;
use App\Ai\Tools\SearchCommits;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Enums\CommitStatus;
use App\Enums\Feature;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\ReplyAsTom;
use App\Models\Commit;
use App\Models\KnowledgeBaseSection;
use App\Models\User;
use App\Services\Features;
use App\Services\TomToolDecision;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Tools\Request as ToolRequest;

it('uses the reserved bot handle in its instructions after the real tom joins', function (): void {
    User::factory()->create(['username' => 'devnull-tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $owner = User::factory()->create(['username' => 'tom']);
    $question = Commit::factory()->for($owner)->create(['body' => '@devnull-tom hello']);

    $instructions = new Tom($question)->instructions();

    expect($instructions)->toContain('You are Tom (@devnull-tom)', 'The latest message is by @tom.');
});

it('forces the selected tools before Tom answers and supports a bounded follow-up search', function (): void {
    config(['ai.providers.openai.key' => 'test-openai', 'ai.providers.opencode.key' => 'test-opencode']);
    Queue::fake([EmbedCommit::class, ModerateCommit::class, ReplyAsTom::class]);
    resolve(Features::class)->update(Feature::Translations, false);
    Embeddings::fake([[embeddingVector(0)], [embeddingVector(0)]]);
    $public = Commit::factory()->for(User::factory()->state(['location' => 'Portugal']))->create(['body' => 'Public database topic', 'embedding' => embeddingVector(0)]);
    Commit::factory()->pending()->create(['body' => 'Pending secret', 'embedding' => embeddingVector(0)]);
    Commit::factory()->rejected()->create(['body' => 'Rejected secret', 'embedding' => embeddingVector(0)]);
    Commit::factory()->create(['body' => 'Deleted secret', 'deleted_at' => now(), 'embedding' => embeddingVector(0)]);
    KnowledgeBaseSection::factory()->create(['headings' => ['Stack'], 'content' => 'The platform uses PostgreSQL.', 'embedding' => embeddingVector(0)]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $question = Commit::factory()->create(['body' => '@tom explain the database and find posts about it', 'mentions' => ['tom' => $tom->id]]);
    User::factory()->create(['github_id' => '123', 'username' => 'alice-local']);
    $calls = [
        ['name' => 'SearchCommits', 'arguments' => '{"query":"database"}'],
        ['name' => 'SearchKnowledgeBase', 'arguments' => '{"query":"platform database"}'],
        ['name' => 'RepositoryInsights', 'arguments' => '{"metric":"languages"}'],
        ['name' => 'SearchRepositoryHistory', 'arguments' => '{"query":"database search"}'],
        ['name' => 'CommunityStats', 'arguments' => '{"metric":"location","term":"Portugal"}'],
        ['name' => 'SearchRepositoryHistory', 'arguments' => '{"query":"semantic search"}'],
    ];
    $responses = Http::sequence();
    foreach ($calls as $index => $call) {
        $responses->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call-'.$index, 'type' => 'function', 'function' => $call],
            ],
        ]]]]);
    }

    $responses->push(['choices' => [['finish_reason' => 'stop', 'message' => [
        'role' => 'assistant', 'content' => 'PostgreSQL, with a side of rust. :-D',
    ]]]]);
    Http::fake([
        'api.openai.com/v1/decisions' => Http::sequence()->push(['answers' => collect($calls)->take(5)->map(fn (array $call): array => [
            'name' => $call['name'], 'type' => 'predicate', 'probability' => 1,
        ])->all()])->push(relevanceResponse([true])),
        'api.github.com/repos/dev-null-sh/dev-null.sh/languages' => Http::response(['PHP' => 123]),
        'api.github.com/search/issues*' => Http::response(['incomplete_results' => false, 'items' => []]),
        'api.github.com/search/commits*' => Http::sequence()->push(['incomplete_results' => false, 'items' => [[
            'html_url' => 'https://github.com/dev-null-sh/dev-null.sh/commit/abc',
            'author' => ['login' => 'alice', 'id' => 123], 'committer' => ['login' => 'not-the-author'],
            'commit' => ['message' => "feat: database search\n\nsecret trailer", 'author' => ['email' => 'secret@example.com', 'date' => '2025-01-01T00:00:00Z']],
        ]]])->push(['incomplete_results' => false, 'items' => [[
            'html_url' => 'https://github.com/dev-null-sh/dev-null.sh/commit/def',
            'author' => ['login' => 'bob', 'id' => 456],
            'commit' => ['message' => 'feat: semantic search', 'author' => ['date' => '2024-01-01T00:00:00Z']],
        ]]]),
        'opencode.ai/zen/v1/chat/completions' => $responses,
    ]);

    resolve(CreateTomReply::class)->handle($question->id);

    expect($question->replies()->sole()->body)->toBe('PostgreSQL, with a side of rust. :-D');
    $requests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions')->pluck(0);
    expect($requests)->toHaveCount(7);
    expect($requests->take(5)->map(fn (Request $request): array => [
        $request['tool_choice']['function']['name'], collect($request['tools'])->pluck('function.name')->all(),
    ])->all())->toBe([
        ['SearchCommits', ['SearchCommits']],
        ['SearchKnowledgeBase', ['SearchKnowledgeBase']],
        ['RepositoryInsights', ['RepositoryInsights']],
        ['SearchRepositoryHistory', ['SearchRepositoryHistory']],
        ['CommunityStats', ['CommunityStats']],
    ]);
    expect($requests->get(5)['tools'])->toHaveCount(5);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
        && $request['model'] === 'gpt-6-luna'
        && collect($request['questions'])->pluck('name')->all() === ['SearchCommits', 'SearchKnowledgeBase', 'RepositoryInsights', 'SearchRepositoryHistory', 'CommunityStats']
        && collect($request['questions'])->every(fn (array $question): bool => $question['type'] === 'predicate'));
    Http::assertSent(function (Request $request) use ($public): bool {
        if ($request->url() !== 'https://opencode.ai/zen/v1/chat/completions') {
            return false;
        }

        $tools = collect($request['messages'])->where('role', 'tool')->pluck('content')->implode(' ');

        return $request['model'] === 'deepseek-v4.1-flash'
            && empty($request['tools'])
            && $request->hasHeader('Authorization', 'Bearer test-opencode')
            && $request['thinking'] === ['type' => 'disabled']
            && str_contains($tools, 'The platform uses PostgreSQL.')
            && str_contains($tools, 'Public database topic')
            && str_contains($tools, '"languages":{"PHP":123}')
            && str_contains($tools, '"commit_author":"alice"')
            && str_contains($tools, '"mention":"@alice-local"')
            && str_contains(stripslashes($tools), 'https://github.com/dev-null-sh/dev-null.sh/commit/abc')
            && str_contains(stripslashes($tools), 'https://github.com/dev-null-sh/dev-null.sh/commit/def')
            && ! str_contains($tools, 'not-the-author')
            && str_contains($tools, '"count":1')
            && str_contains($tools, 'Not nationality or verified residence')
            && str_contains(stripslashes($tools), route('commit.show', [$public->user, $public]))
            && ! str_contains($tools, 'secret')
            && ! str_contains($tools, 'embedding')
            && ! str_contains($tools, 'github_id');
    });
});

it('retains all three search sources through retries and removes only spent tools from follow-ups', function (): void {
    config(['ai.providers.openai.key' => 'test-openai', 'ai.providers.opencode.key' => 'test-opencode']);
    Queue::fake([EmbedCommit::class, ModerateCommit::class, ReplyAsTom::class]);
    resolve(Features::class)->update(Feature::Translations, false);
    Embeddings::fake([[embeddingVector(0)]]);
    Commit::factory()->create(['body' => 'I like the new badges.', 'embedding' => embeddingVector(0)]);
    KnowledgeBaseSection::factory()->create(['headings' => ['Badges'], 'content' => 'Badges reward milestones.', 'embedding' => embeddingVector(0)]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $previous = Commit::factory()->for($tom)->create(['body' => 'My knowledge-base budget is exhausted.']);
    $question = Commit::factory()->for($previous, 'parent')->create(['body' => 'Tell me about badges, how they evolved and what people think of them.']);
    $calls = ['SearchCommits', null, 'SearchKnowledgeBase', null,
        'SearchRepositoryHistory', 'SearchKnowledgeBase', 'SearchCommits', 'SearchRepositoryHistory'];
    $responses = Http::sequence();
    foreach ($calls as $index => $name) {
        $responses->push(['choices' => [['finish_reason' => $name === null ? 'stop' : 'tool_calls', 'message' => $name === null
            ? ['role' => 'assistant', 'content' => 'Badges reward milestones.']
            : ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call-'.$index, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '{"query":"badges"}']],
            ]],
        ]]]);
    }

    $responses->push(['choices' => [['finish_reason' => 'stop', 'message' => [
        'role' => 'assistant', 'content' => 'Badges reward milestones. Members like them, and the repository records their introduction.',
    ]]]]);
    $decisions = Http::sequence()->push(['answers' => collect(['SearchCommits', 'SearchKnowledgeBase', 'SearchRepositoryHistory'])
        ->map(fn (string $name): array => ['name' => $name, 'type' => 'predicate', 'probability' => 1])->all()]);
    foreach (range(1, 2) as $search) {
        $decisions->push(relevanceResponse([true]));
    }

    Http::fake([
        'api.openai.com/v1/decisions' => $decisions,
        'api.github.com/search/issues*' => Http::response(['incomplete_results' => false, 'items' => []]),
        'api.github.com/search/commits*' => Http::response(['incomplete_results' => false, 'items' => [[
            'html_url' => 'https://github.com/dev-null-sh/dev-null.sh/commit/abc',
            'author' => ['login' => 'alice', 'id' => 123],
            'commit' => ['message' => 'feat: introduce badges', 'author' => ['date' => '2026-10-20T00:00:00Z']],
        ]]]),
        'opencode.ai/zen/v1/chat/completions' => $responses,
    ]);

    resolve(CreateTomReply::class)->handle($question->id);

    expect($question->replies()->sole()->body)->toBe('Badges reward milestones. Members like them, and the repository records their introduction.');
    $requests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions')->pluck(0);
    expect($requests)->toHaveCount(9);
    expect($requests[2]['tool_choice']['function']['name'])->toBe('SearchKnowledgeBase');
    expect($requests[4]['tool_choice']['function']['name'])->toBe('SearchRepositoryHistory');
    expect(collect($requests[4]['messages'])->where('role', 'tool')->pluck('content')->implode(' '))
        ->toContain('Badges reward milestones.', 'I like the new badges.');
    expect(collect($requests[5]['tools'])->pluck('function.name')->all())->toBe(['SearchCommits', 'SearchKnowledgeBase', 'SearchRepositoryHistory']);
    expect(collect($requests[6]['tools'])->pluck('function.name')->all())->toBe(['SearchCommits', 'SearchRepositoryHistory']);
    expect(collect($requests[7]['tools'])->pluck('function.name')->all())->toBe(['SearchRepositoryHistory']);
    expect($requests[8]['tools'] ?? [])->toBeEmpty();

    $results = collect($requests[8]['messages'])->where('role', 'tool');
    expect($results->pluck('tool_call_id')->all())->toBe(['call-0', 'call-2', 'call-4', 'call-5', 'call-6', 'call-7']);
    $evidence = $results->pluck('content')->implode(' ');
    expect($evidence)->toContain('Badges reward milestones.', 'I like the new badges.', 'feat: introduce badges')
        ->not->toContain('budget exhausted');
    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.openai.com/v1/decisions' || ! str_contains($request['input'], 'Tell me about badges')) {
            return false;
        }

        $criteria = json_encode($request['questions']);
        expect($criteria)->toContain('notable events', 'Sources are complementary, not mutually exclusive', 'how it evolved', 'community reactions');

        return true;
    });
});

it('routes a dated signup question to community statistics and supplies the calendar count as evidence', function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 10, 21)->startOfDay());
    config(['ai.providers.openai.key' => 'test-openai', 'ai.providers.opencode.key' => 'test-opencode']);
    Queue::fake([EmbedCommit::class, ModerateCommit::class, ReplyAsTom::class]);
    resolve(Features::class)->update(Feature::Translations, false);
    User::factory()->count(2)->create(['created_at' => '2026-10-20 12:00:00']);
    User::factory()->create(['created_at' => '2026-10-19 12:00:00']);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $question = Commit::factory()->create(['body' => '@tom how many people joined on October 20, 2026?', 'mentions' => ['tom' => $tom->id]]);
    Http::fake([
        'api.openai.com/v1/decisions' => Http::response(['answers' => [
            ['name' => 'CommunityStats', 'type' => 'predicate', 'probability' => 1],
        ]]),
        'opencode.ai/zen/v1/chat/completions' => Http::sequence()->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call-signups', 'type' => 'function', 'function' => [
                    'name' => 'CommunityStats', 'arguments' => '{"metric":"new_members","start_date":"2026-10-20","end_date":"2026-10-20"}',
                ]],
            ],
        ]]]])->push(['choices' => [['finish_reason' => 'stop', 'message' => [
            'role' => 'assistant', 'content' => 'Two current members joined on October 20, 2026 (UTC).',
        ]]]]),
    ]);

    resolve(CreateTomReply::class)->handle($question->id);

    expect($question->replies()->sole()->body)->toBe('Two current members joined on October 20, 2026 (UTC).');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
        && str_contains($request['input'], $question->body)
        && str_contains(json_encode($request['questions']), 'particular calendar date'));
    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://opencode.ai/zen/v1/chat/completions') {
            return false;
        }

        $evidence = collect($request['messages'])->where('role', 'tool')->pluck('content')->implode(' ');

        return str_contains($evidence, '"count":2') && str_contains($evidence, '"start_date":"2026-10-20"')
            && str_contains($evidence, '"end_date":"2026-10-20"') && str_contains($evidence, '"timezone":"UTC"');
    });
});

it('uses public thread context for the decision and falls back to chat when no tool is selected', function (): void {
    config(['ai.providers.openai.key' => 'test-openai', 'ai.providers.opencode.key' => 'test-opencode']);
    Queue::fake([EmbedCommit::class, ModerateCommit::class, ReplyAsTom::class]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $root = Commit::factory()->create(['body' => 'How do bookmarks work?']);
    $answer = Commit::factory()->for($root, 'parent')->for($tom)->create(['body' => 'They save posts for later.']);
    Commit::factory()->for($root, 'parent')->create(['body' => 'Unrelated sibling']);
    $question = Commit::factory()->for($answer, 'parent')->create(['body' => 'Thanks, Tom!']);
    Http::fake([
        'api.openai.com/v1/decisions' => Http::response(['answers' => []]),
        'opencode.ai/zen/v1/chat/completions' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'You are welcome. :-)']]],
        ]),
    ]);

    resolve(CreateTomReply::class)->handle($question->id);

    expect($question->replies()->sole()->body)->toBe('You are welcome. :-)');
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'))->toHaveCount(1);
    Http::assertSent(function (Request $request) use ($question): bool {
        if ($request->url() !== 'https://api.openai.com/v1/decisions') {
            return false;
        }

        $input = json_encode($request['input']);
        expect($input)->toContain('How do bookmarks work?', 'They save posts for later.', $question->body)
            ->not->toContain('Unrelated sibling');

        return $request['model'] === 'gpt-6-luna';
    });
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions'
        && empty($request['tools']));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.github.com') || str_contains($request->url(), '/embeddings'));
});

it('retries skipped tools at most twice and never publishes an incomplete reply', function (int $skippedAttempts): void {
    config(['ai.providers.openai.key' => 'test-openai', 'ai.providers.opencode.key' => 'test-opencode']);
    Queue::fake([EmbedCommit::class, ModerateCommit::class, ReplyAsTom::class]);
    resolve(Features::class)->update(Feature::Translations, false);
    Embeddings::fake([[embeddingVector(0)]]);
    KnowledgeBaseSection::factory()->create(['headings' => ['Pastéis'], 'content' => 'Pastéis are pastry awards.', 'embedding' => embeddingVector(0)]);
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $root = Commit::factory()->create(['body' => 'What are pastels here?']);
    $answer = Commit::factory()->for($root, 'parent')->for($tom)->create(['body' => 'Pastels are pale colors.']);
    $question = Commit::factory()->for($answer, 'parent')->create(['body' => 'I MEANT PASTEIS, TOM']);
    $responses = Http::sequence();
    foreach (range(1, $skippedAttempts) as $attempt) {
        $responses->push([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Let me check. Searching now.']]],
        ]);
    }

    if ($skippedAttempts < 3) {
        $responses->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call-pasteis', 'type' => 'function', 'function' => ['name' => 'SearchKnowledgeBase', 'arguments' => '{"query":"pasteis awards"}']],
            ],
        ]]]])->push(['choices' => [['finish_reason' => 'stop', 'message' => [
            'role' => 'assistant', 'content' => 'Pastéis are pastry awards.',
        ]]]]);
    }

    Http::fake([
        'api.openai.com/v1/decisions' => Http::response(['answers' => [
            ['name' => 'SearchKnowledgeBase', 'type' => 'predicate', 'probability' => 1],
        ]]),
        'opencode.ai/zen/v1/chat/completions' => $responses,
    ]);

    if ($skippedAttempts === 3) {
        expect(fn () => resolve(CreateTomReply::class)->handle($question->id))
            ->toThrow(RuntimeException::class, 'Tom skipped a selected tool after retries.');
        expect($question->replies()->exists())->toBeFalse();
    } else {
        resolve(CreateTomReply::class)->handle($question->id);

        expect($question->replies()->sole()->body)->toBe('Pastéis are pastry awards.');
    }

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions'))
        ->toHaveCount($skippedAttempts === 3 ? 3 : $skippedAttempts + 2);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
        && str_contains($request['input'], $root->body)
        && str_contains($request['input'], $question->body));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opencode.ai/zen/v1/chat/completions'
        && ($request['tool_choice']['function']['name'] ?? null) === 'SearchKnowledgeBase');
})->with([1, 2, 3]);

it('selects only known affirmative predicates at or above the probability threshold', function (): void {
    Http::fake(['api.openai.com/v1/decisions' => Http::response(['answers' => [
        ['name' => 'CommunityStats', 'type' => 'predicate', 'probability' => 0.5],
        ['name' => 'SearchKnowledgeBase', 'type' => 'predicate', 'probability' => 0.49],
        ['name' => 'RepositoryInsights', 'type' => 'refusal', 'probability' => 1],
        ['name' => 'SearchCommits', 'type' => 'choice', 'choice' => 'yes'],
        ['name' => 'SearchRepositoryHistory', 'type' => 'predicate', 'probability' => 1.1],
        ['name' => 'UnknownTool', 'type' => 'predicate', 'probability' => 1],
        'invalid answer',
    ]])]);

    $tools = resolve(TomToolDecision::class)->decide('How many members are here?', []);

    expect($tools->map(class_basename(...))->all())->toBe(['CommunityStats']);
});

it('tells Tom which addresses it may write without a tool result', function (): void {
    $question = Commit::factory()->create(['body' => 'Where is the knowledge base?']);

    expect(new Tom($question)->instructions())
        ->toContain('never give a URL for it', 'https://github.com/dev-null-sh/dev-null.sh');
});

it('remembers only the public ancestor branch with the correct speakers', function (): void {
    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $user = User::factory()->create(['username' => 'alice']);
    $root = Commit::factory()->for($user)->create(['body' => 'What is this place?']);
    $answer = Commit::factory()->for($root, 'parent')->for($tom)->create(['body' => 'A developer community.']);
    Commit::factory()->for($root, 'parent')->create(['body' => 'A different branch.']);
    Commit::factory()->create(['body' => 'An unrelated thread.']);
    $newcomer = User::factory()->create(['username' => 'bob']);
    $question = Commit::factory()->for($answer, 'parent')->for($newcomer)->create(['body' => '@tom and who started it?']);

    expect(new Tom($question)->messages()->map(fn (Message $message): array => [$message->role->value, $message->content])->all())->toBe([
        ['user', '@alice: What is this place?'],
        ['assistant', '@tom: A developer community.'],
    ]);
    expect(new Tom($question)->instructions())->toContain('The latest message is by @bob.')
        ->not->toContain('@alice', '2012 RAM');

    $root->update(['status' => CommitStatus::Rejected]);
    expect(new Tom($question)->messages()->pluck('content')->all())->toBe(['@tom: A developer community.']);
    $answer->delete();
    expect(new Tom($question)->messages())->toBeEmpty();
});

it('bounds history by both posts and characters and signals a nearly full window', function (int $length, int $expected): void {
    $user = User::factory()->create(['username' => 'alice']);
    $parent = null;
    for ($index = 0; $index < 15; $index++) {
        $parent = Commit::factory()->for($user)->create(['body' => str_repeat('x', $length), 'parent_id' => $parent?->id]);
    }

    $question = Commit::factory()->for($parent, 'parent')->for($user)->create(['body' => '@tom '.str_repeat('y', 995)]);
    $agent = new Tom($question);

    expect($agent->messages())->toHaveCount($expected);
    expect($agent->messages()->sum(fn (Message $message): int => mb_strlen($message->content)) + mb_strlen($question->body))->toBeLessThanOrEqual(12000);
    expect($agent->instructions())->toContain('2012 RAM');
})->with(['message limit' => [10, 12], 'character limit' => [3500, 3]]);

it('caps tool results and search calls for each reply', function (): void {
    Embeddings::fake([[embeddingVector(0)]]);
    KnowledgeBaseSection::factory()->count(4)->create(['content' => str_repeat('k', 4000), 'embedding' => embeddingVector(0)]);
    $tool = new SearchKnowledgeBase;
    $request = new ToolRequest(['query' => 'origins']);

    $results = json_decode($tool->handle($request), true);
    expect($results)->toHaveCount(3);
    expect(array_keys($results[0]))->toBe(['headings', 'content']);
    expect(mb_strlen($results[0]['content']))->toBe(3000);

    $tool->handle($request);
    expect($tool->handle($request))->toContain('budget exhausted');

    $commits = new SearchCommits;
    expect($commits->handle(new ToolRequest(['query' => 'origins'])))->toBe('[]');
    $commits->handle($request);
    expect($commits->handle($request))->toContain('budget exhausted');
});
