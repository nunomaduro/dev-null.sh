<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListUserFollowersYouKnowRequest;
use App\Models\User;
use App\Queries\FollowersYouKnowQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

final readonly class UserFollowersYouKnowController
{
    public function index(ListUserFollowersYouKnowRequest $request, #[CurrentUser] User $currentUser, User $user, FollowersYouKnowQuery $followersYouKnow): Response
    {
        return Inertia::render('user/connections', [
            'user' => $user,
            'type' => 'followers_you_know',
            'users' => Inertia::scroll(fn () => $followersYouKnow->builder($user, $currentUser)
                ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($currentUser->id)])
                ->cursorPaginate(30)),
        ]);
    }
}
