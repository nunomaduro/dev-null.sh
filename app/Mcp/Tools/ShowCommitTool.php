<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\CommitResolver;
use App\Mcp\Presenter;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitAncestorsQuery;
use App\Queries\CommitRepliesQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Open a commit with the thread it replies to and its first replies.')]
#[IsReadOnly]
final class ShowCommitTool extends Tool
{
    public function handle(Request $request, Presenter $presenter, CommitResolver $resolver, CommitAncestorsQuery $ancestors, CommitRepliesQuery $replies): Response|ResponseFactory
    {
        $user = $request->user();
        assert($user instanceof User);

        $validated = $request->validate([
            'commit' => ['required', 'string', 'max:300'],
        ]);

        $commit = $resolver->resolve($user, $validated['commit']);

        if ($commit instanceof Response) {
            return $commit;
        }

        $commit = Commit::query()
            ->withVisibleReplyCount($user)
            ->with('user')
            ->withStarsFor($user)
            ->findOrFail($commit->id);

        return Response::structured([
            'commit' => $presenter->commit($commit),
            'ancestors' => $ancestors->get($commit, $user)
                ->map(fn (Commit $ancestor): array => $presenter->commit($ancestor))
                ->values()
                ->all(),
            'replies' => $replies->paginate($commit, $user)->getCollection()
                ->map(fn (Commit $reply): array => $presenter->commit($reply))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'commit' => $schema->string()
                ->description('The commit hash (last 7 characters of its ID), its full ID, or its link.')
                ->required(),
        ];
    }
}
