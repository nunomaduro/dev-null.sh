<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Jobs\EraseDeletedCommitImages;
use App\Models\Commit;
use App\Models\CommitReport;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCommit
{
    public function __construct(private UpdateCommitHotScores $updateCommitHotScores)
    {
        //
    }

    /**
     * Soft deleting keeps the row for its replies and quotes, so its notifications, the notifications about its replies and
     * quotes, and reports are removed here instead of by the foreign key, one indexed query each.
     * Its images are kept until the undo window closes, so undoing the deletion brings them back too.
     */
    public function handle(Commit $commit): Commit
    {
        return DB::transaction(function () use ($commit): Commit {
            $commit = Commit::withTrashed()->lockForUpdate()->findOrFail($commit->id);

            if ($commit->trashed()) {
                return $commit;
            }

            Notification::query()->where('commit_id', $commit->id)->delete();
            Notification::query()->where('type', NotificationType::Replied)->whereIn('commit_id', $commit->replies()->select('id'))->delete();
            Notification::query()->where('type', NotificationType::Quoted)->whereIn('commit_id', $commit->quotes()->select('id'))->delete();

            CommitReport::query()->where('commit_id', $commit->id)->delete();

            $commit->delete();

            $this->updateCommitHotScores->handle([$commit->id]);

            dispatch(new EraseDeletedCommitImages($commit))
                ->delay(now()->addSeconds(Commit::RESTORABLE_SECONDS))
                ->afterCommit();

            return $commit->refresh();
        }, attempts: 3);
    }
}
