<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateLevelRewardClaim;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class LevelRewardController
{
    public function store(#[CurrentUser] User $user, int $level, CreateLevelRewardClaim $action): RedirectResponse
    {
        if (! $action->handle($user, $level)) {
            return back()->withErrors(['reward' => __('Level reward is not available or was already claimed.')]);
        }

        return back();
    }
}
