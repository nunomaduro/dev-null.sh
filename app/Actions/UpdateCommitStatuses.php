<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\CheckUserLevel;
use App\Jobs\ClassifyCommitTopics;
use App\Jobs\ReplyAsTom;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\Poll;
use App\Models\User;
use App\Queries\CommitBuilder;
use App\Queries\TomQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UpdateCommitStatuses
{
    public function __construct(
        private CreateReplyNotifications $createReplyNotifications,
        private CreateQuoteNotifications $createQuoteNotifications,
        private CreateMentionNotifications $createMentionNotifications,
        private CreateWorldReply $createWorldReply,
        private UpdateCommitHotScores $updateCommitHotScores,
        private TomQuery $tomQuery,
    ) {
        //
    }

    /**
     * @param  array<int, string>  $commitIds
     */
    public function handle(array $commitIds, CommitStatus $status, ?string $reason = null, bool $onlyPending = false): void
    {
        assert($status !== CommitStatus::Pending);

        DB::transaction(function () use ($commitIds, $status, $reason, $onlyPending): void {
            $commits = Commit::query()
                ->whereKey($commitIds)
                ->when($onlyPending, fn (CommitBuilder $query): CommitBuilder => $query->pending())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Commit $commit): bool => $commit->status !== $status);

            if ($commits->isEmpty()) {
                return;
            }

            Commit::query()->whereKey($commits->modelKeys())->update(['status' => $status]);

            Notification::query()
                ->whereIn('commit_id', $commits->modelKeys())
                ->whereIn('type', [NotificationType::Approved, NotificationType::Rejected])
                ->delete();

            if ($status === CommitStatus::Rejected) {
                Notification::query()->insert($commits->map(fn (Commit $commit): array => [
                    'id' => Str::uuid7()->toString(),
                    'recipient_id' => $commit->user_id,
                    'actor_id' => $commit->user_id,
                    'type' => NotificationType::Rejected->value,
                    'commit_id' => $commit->id,
                    'key' => 'moderated:'.$commit->id,
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->values()->all());
            }

            $this->updateCommitHotScores->handle($commits->modelKeys());

            if ($status === CommitStatus::Approved) {
                Poll::query()
                    ->whereIn('commit_id', $commits->modelKeys())
                    ->whereNull('opened_at')
                    ->update(['opened_at' => now()]);

                $this->createReplyNotifications->handle(...$commits);
                $this->createQuoteNotifications->handle(...$commits);
                $this->createMentionNotifications->handle(...$commits);

                $commits->each(fn (Commit $commit) => $this->createWorldReply->handle($commit));

                foreach ($commits->filter(fn (Commit $commit): bool => $commit->parent_id === null && $commit->body !== ''
                    && $commit->topic_decision === null)->chunk(40) as $batch) {
                    dispatch(new ClassifyCommitTopics(array_values($batch->map(fn (Commit $commit): string => $commit->id)->all())))->afterCommit();
                }

                if (($tom = $this->tomQuery->get()) instanceof User) {
                    foreach ($commits as $commit) {
                        if ($commit->user?->github_id !== CreateTomFriendship::GITHUB_ID
                            && $commit->isAddressedTo($tom)) {
                            dispatch(new ReplyAsTom($commit->id));
                        }
                    }
                }

                User::query()->whereKey($commits->pluck('user_id')->filter()->unique()->values()->all())->get()
                    ->each(fn (User $author) => dispatch(new CheckUserLevel($author))->afterCommit());
            }
        });
    }
}
