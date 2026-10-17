<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

final readonly class UpdateUserRemotes
{
    public function handle(User $user, bool $enabled): void
    {
        $user->update(['remotes_enabled' => $enabled]);
    }
}
