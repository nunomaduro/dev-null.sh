<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateUserFollow;
use App\Actions\DeleteUserFollow;
use App\Http\Requests\CreateApiUserFollowRequest;
use App\Http\Requests\DeleteApiUserFollowRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class UserFollowController
{
    public function store(CreateApiUserFollowRequest $request, #[CurrentUser] User $follower, User $user, CreateUserFollow $action): Response
    {
        $action->handle($follower, $user);

        return response()->noContent();
    }

    public function destroy(DeleteApiUserFollowRequest $request, #[CurrentUser] User $follower, User $user, DeleteUserFollow $action): Response
    {
        $action->handle($follower, $user);

        return response()->noContent();
    }
}
