<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateUserBan;
use App\Http\Requests\CreateUserBanRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class UserBanController
{
    /**
     * The banned user's profile is gone, so this goes back to the reports rather than to where the ban came from.
     */
    public function store(CreateUserBanRequest $request, User $user, CreateUserBan $action): RedirectResponse
    {
        $action->handle($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Banned @:username. Their profile, commits and replies are hidden, and they can no longer sign in.', ['username' => $user->username]),
        ]);

        return to_route('reported-commit.index');
    }
}
