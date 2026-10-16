<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

final readonly class UpdateMessagingSettings
{
    public function handle(User $user, string $policy, bool $readReceipts): void
    {
        $user->update(['dm_policy' => $policy, 'dm_read_receipts' => $readReceipts]);
    }
}
