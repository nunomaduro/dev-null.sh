<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ScheduledCommit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class ScheduledCommitsQuery
{
    /**
     * The user's scheduled commits that weren't canceled: the ones still waiting first, then the submitted ones, each
     * by when they were due.
     *
     * @return Builder<ScheduledCommit>
     */
    public function builder(User $user): Builder
    {
        return ScheduledCommit::query()->where('user_id', $user->id)
            ->whereNull('canceled_at')->with('commit')
            ->orderByRaw('submitted_at IS NOT NULL')->orderBy('scheduled_at')->orderBy('id');
    }
}
