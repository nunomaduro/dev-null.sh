<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Ban;
use App\Models\User;

final readonly class CreateUserBan
{
    /**
     * Bans the user's GitHub account rather than the user, so the ban still holds if the account is deleted or the
     * username changes. Nothing is deleted: the user and everything they did is only hidden while the ban lasts.
     */
    public function handle(User $user): void
    {
        Ban::query()->create(['github_id' => $user->github_id]);
    }
}
