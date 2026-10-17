<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateUserBlock;
use App\Actions\DeleteUserBlock;
use App\Http\Requests\CreateUserBlockRequest;
use App\Http\Requests\DeleteUserBlockRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class UserBlockController
{
    public function store(CreateUserBlockRequest $request, #[CurrentUser] User $currentUser, User $user, CreateUserBlock $action): RedirectResponse
    {
        $action->handle($currentUser, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __("Blocked @:username. Neither of you can message, follow, reply to, or star the other, or see each other's commits.", ['username' => $user->username]),
        ]);

        return back();
    }

    public function destroy(DeleteUserBlockRequest $request, #[CurrentUser] User $currentUser, User $user, DeleteUserBlock $action): RedirectResponse
    {
        $action->handle($currentUser, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Unblocked @:username.', ['username' => $user->username]),
        ]);

        return back();
    }
}
