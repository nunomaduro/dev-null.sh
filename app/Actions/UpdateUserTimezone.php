<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

final readonly class UpdateUserTimezone
{
    public function handle(User $user, ?string $timezone): void
    {
        $user->update(['timezone' => $timezone]);
    }
}
