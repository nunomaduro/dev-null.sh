<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\UpdateScheduledCommitSubmittedAt;
use App\Models\ScheduledCommit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('commits:submit-scheduled')]
#[Description('Submit up to 100 due scheduled commits through the normal review process')]
final class SubmitScheduledCommitsCommand extends Command
{
    /**
     * What banned users scheduled waits, and goes out if the ban is lifted.
     */
    public function handle(UpdateScheduledCommitSubmittedAt $action): int
    {
        $failed = false;
        $scheduledCommits = ScheduledCommit::query()->whereNull('submitted_at')->whereNull('canceled_at')->whereHas('user')
            ->where('scheduled_at', '<=', now())->oldest('scheduled_at')->orderBy('id')->limit(100)->get(['id']);

        foreach ($scheduledCommits as $scheduled) {
            try {
                $action->handle($scheduled->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Could not submit scheduled commit '.$scheduled->id.'. It will be retried.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
