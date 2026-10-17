<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserRemotes;
use App\Http\Requests\UpdateUserRemotesRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class UserRemotesController
{
    public function update(UpdateUserRemotesRequest $request, #[CurrentUser] User $user, UpdateUserRemotes $action): RedirectResponse
    {
        $action->handle($user, $request->boolean('enabled'));

        return back();
    }
}
