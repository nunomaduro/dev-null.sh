<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RemotesFrom;
use App\Models\User;

final readonly class UpdateUserRemotePreferences
{
    /**
     * Applies to conversations started from now on: requests already fetched stay fetched.
     */
    public function handle(User $user, RemotesFrom $from, bool $pullReceipts): void
    {
        $user->update(['remotes_from' => $from, 'pull_receipts' => $pullReceipts]);
    }
}
