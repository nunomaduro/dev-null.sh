<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Log;
use App\Mcp\Presenter;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the log: the latest commits from the people the user follows (and their own), the latest commits from everyone, or trending commits. Returns 20 commits per page. Pass the returned next_cursor to read older ones.')]
#[IsReadOnly]
final class ShowLogTool extends Tool
{
    private const int PER_PAGE = 20;

    public function handle(Request $request, Presenter $presenter): ResponseFactory
    {
        $user = $request->user();
        assert($user instanceof User);

        /** @var array{log?: string, cursor?: string|null} $validated */
        $validated = $request->validate([
            'log' => ['sometimes', Rule::enum(Log::class)],
            'cursor' => ['nullable', 'string'],
        ]);

        $log = Log::from($validated['log'] ?? Log::Following->value);

        $commits = $log->query()
            ->builder($user)
            ->cursorPaginate(self::PER_PAGE, cursor: $validated['cursor'] ?? null);

        return Response::structured([
            'log' => $log->value,
            'commits' => $commits->getCollection()
                ->map(fn (Commit $commit): array => $presenter->commit($commit))
                ->all(),
            'next_cursor' => $commits->nextCursor()?->encode(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'log' => $schema->string()
                ->enum(array_map(fn (Log $log): string => $log->value, Log::cases()))
                ->description('Whose commits to read. Defaults to following.'),
            'cursor' => $schema->string()->description('The next_cursor of the previous page.'),
        ];
    }
}
