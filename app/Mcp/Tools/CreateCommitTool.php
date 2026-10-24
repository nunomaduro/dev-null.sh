<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateCommit;
use App\Enums\CommitStatus;
use App\Mcp\Presenter;
use App\Models\Commit;
use App\Models\User;
use App\Rules\VisibleCommit;
use App\Services\CommitBodies;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Commit as the signed-in user, or reply to a commit. Commits from non-admins are pending until a moderator approves them, and only the author sees them until then. Limited to 10 commits a minute.')]
final class CreateCommitTool extends Tool
{
    private const int MAX_ATTEMPTS = 10;

    public function handle(Request $request, Presenter $presenter, CommitBodies $bodies, CreateCommit $action): Response|ResponseFactory
    {
        $user = $request->user();
        assert($user instanceof User);

        if (is_string($request->get('body'))) {
            $request->merge(['body' => $bodies->normalize($request->get('body'))]);
        }

        /** @var array{body: string, parent_id?: string|null} $validated */
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:'.Commit::MIN_BODY_LENGTH, 'max:'.Commit::MAX_BODY_LENGTH],
            'parent_id' => ['nullable', 'uuid', new VisibleCommit($user)],
        ]);

        $key = "mcp-commits:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return Response::error('Slow down: 10 commits a minute. Even CI takes breaks.');
        }

        RateLimiter::hit($key);

        $parent = isset($validated['parent_id'])
            ? Commit::query()->visibleTo($user)->find($validated['parent_id'])
            : null;

        $commit = $action->handle($user, $validated['body'], $parent);

        return Response::structured([
            'commit' => $presenter->commit($commit->load('user')),
            'pending_review' => $commit->status === CommitStatus::Pending,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->description(sprintf('What to commit: %d to %s characters.', Commit::MIN_BODY_LENGTH, number_format(Commit::MAX_BODY_LENGTH)))->required(),
            'parent_id' => $schema->string()->description('The full ID of the commit to reply to. Leave out for a new commit.'),
        ];
    }
}
