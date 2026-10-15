<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateUserFollow;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Follow a user as the signed-in user. Following twice changes nothing.')]
#[IsIdempotent]
final class CreateUserFollowTool extends Tool
{
    public function handle(Request $request, CreateUserFollow $action): Response
    {
        $follower = $request->user();
        assert($follower instanceof User);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('username', mb_ltrim($validated['username'], '@'))->first();

        if (! $user instanceof User) {
            return Response::error('No user found with that username.');
        }

        if ($follower->is($user)) {
            return Response::error('You cannot follow yourself.');
        }

        $action->handle($follower, $user);

        return Response::text("Now following @{$user->username}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'username' => $schema->string()->description('The username to follow. A leading @ is ignored.')->required(),
        ];
    }
}
