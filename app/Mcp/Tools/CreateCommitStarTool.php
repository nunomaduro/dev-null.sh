<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateCommitStar;
use App\Mcp\CommitResolver;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Star a commit as the signed-in user. Starring twice changes nothing.')]
#[IsIdempotent]
final class CreateCommitStarTool extends Tool
{
    public function handle(Request $request, CommitResolver $resolver, CreateCommitStar $action): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        /** @var array{commit: string} $validated */
        $validated = $request->validate([
            'commit' => ['required', 'string', 'max:300'],
        ]);

        $commit = $resolver->resolve($user, $validated['commit']);

        if (! $commit instanceof Commit) {
            return $commit;
        }

        $action->handle($user, $commit);

        return Response::text("Starred commit {$commit->id}.");
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
