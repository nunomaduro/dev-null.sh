<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ScheduledCommit;
use App\Models\User;
use App\Services\Urls;
use Carbon\CarbonImmutable;

final readonly class UpdateScheduledCommit
{
    public function __construct(private Urls $urls)
    {
        //
    }

    /**
     * Changes the body and the time of a scheduled commit that is still waiting, and gives it the link preview of the
     * first URL in the new body, as when it was scheduled. Its images and poll stay. It returns false, and changes
     * nothing, once the commit was submitted or canceled.
     */
    public function handle(User $user, ScheduledCommit $scheduled, string $body, CarbonImmutable $scheduledAt): bool
    {
        $body = mb_trim($body);

        return ScheduledCommit::query()->whereKey($scheduled->id)->where('user_id', $user->id)
            ->whereNull('submitted_at')->whereNull('canceled_at')
            ->update(['body' => $body, 'link_preview_url' => $this->urls->first($body), 'scheduled_at' => $scheduledAt->utc()]) === 1;
    }
}
