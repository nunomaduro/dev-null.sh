<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Presenter;
use App\Models\User;
use App\Queries\UserSearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Find people on devnull by username or name.')]
#[IsReadOnly]
final class ListUsersTool extends Tool
{
    public function handle(Request $request, Presenter $presenter, UserSearchQuery $query): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:100'],
        ]);

        return Response::structured([
            'users' => $query->get($validated['query'])
                ->map(fn (User $user): array => $presenter->user($user))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Part of a username or name. A leading @ is ignored.')->required(),
        ];
    }
}
