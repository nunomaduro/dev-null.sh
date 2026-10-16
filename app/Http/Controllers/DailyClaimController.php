<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDailyByteClaim;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class DailyClaimController
{
    public function store(
        #[CurrentUser] User $user,
        CreateDailyByteClaim $action,
    ): RedirectResponse {
        $action->handle($user);

        return back();
    }
}
