<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ScheduledCommit;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class UpdateScheduledCommitScheduledAt
{
    public function handle(User $user, ScheduledCommit $scheduled, CarbonImmutable $scheduledAt): bool
    {
        return ScheduledCommit::query()->whereKey($scheduled->id)->where('user_id', $user->id)
            ->whereNull('submitted_at')->whereNull('canceled_at')
            ->update(['scheduled_at' => $scheduledAt->utc()]) === 1;
    }
}
