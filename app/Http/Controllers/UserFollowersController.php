<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListUserFollowersRequest;
use App\Models\User;
use App\Services\PageMetadata;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

final readonly class UserFollowersController
{
    public function index(ListUserFollowersRequest $request, #[CurrentUser] ?User $currentUser, User $user, PageMetadata $metadata): Response
    {
        $countedUser = null;
        $getCountedUser = function () use (&$countedUser, $user): User {
            return $countedUser ??= $user->loadPublicCounts();
        };

        return Inertia::render('user/connections', [
            'user' => fn (): User => $currentUser instanceof User ? $user : $getCountedUser(),
            'type' => 'followers',
            'users' => Inertia::scroll(fn () => $user->followers()
                ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $currentUser instanceof User ? $query->whereKey($currentUser->id) : $query->whereRaw('1 = 0')])
                ->latest('follows.created_at')
                ->latest('users.id')
                ->cursorPaginate(30)),
        ])->withViewData(['preview' => $currentUser instanceof User || $request->inertia() ? null : $metadata->connections($getCountedUser(), 'followers')]);
    }
}
