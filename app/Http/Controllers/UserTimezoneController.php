<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserTimezone;
use App\Http\Requests\UpdateUserTimezoneRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class UserTimezoneController
{
    public function update(UpdateUserTimezoneRequest $request, #[CurrentUser] User $user, UpdateUserTimezone $action): RedirectResponse
    {
        $action->handle($user, $request->timezone());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Timezone saved.'),
        ]);

        return back();
    }
}
