<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserRemotePreferences;
use App\Http\Requests\UpdateUserRemotePreferencesRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class UserRemotePreferencesController
{
    public function update(UpdateUserRemotePreferencesRequest $request, #[CurrentUser] User $user, UpdateUserRemotePreferences $action): RedirectResponse
    {
        $action->handle($user, $request->remotesFrom(), $request->boolean('pull_receipts'));

        return back();
    }
}
