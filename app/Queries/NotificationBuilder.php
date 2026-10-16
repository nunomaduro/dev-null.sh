<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @extends Builder<Notification>
 */
final class NotificationBuilder extends Builder
{
    public function relevant(): self
    {
        return $this->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $query): Builder => $query
                ->where('type', NotificationType::Followed)
                ->whereExists(fn (QueryBuilder $follows): QueryBuilder => $follows
                    ->from('follows')
                    ->whereColumn('follows.follower_id', 'notifications.actor_id')
                    ->whereColumn('follows.followed_id', 'notifications.recipient_id')))
            ->orWhere(fn (Builder $query): Builder => $query
                ->whereIn('type', [NotificationType::Messaged, NotificationType::MessageRequested])
                ->whereHas('recipient', fn (Builder $recipient): Builder => $recipient->where('dm_policy', '!=', 'off'))
                ->whereHas('conversation', fn (Builder $conversation): Builder => $conversation->where('status', '!=', 'declined')
                    ->whereHas('members', fn (Builder $members): Builder => $members->whereColumn('user_id', 'notifications.recipient_id')->where('muted', false)->where('archived', false))))
            ->orWhere(fn (Builder $query): Builder => $query
                ->whereIn('type', [NotificationType::Replied, NotificationType::Quoted, NotificationType::Mentioned, NotificationType::Starred])
                ->whereHas('commit', fn (CommitBuilder $commit): CommitBuilder => $commit
                    ->where('status', CommitStatus::Approved)))
            ->orWhere(fn (Builder $query): Builder => $query
                ->whereIn('type', [NotificationType::Approved, NotificationType::Rejected])
                ->whereHas('commit', fn (CommitBuilder $commit): CommitBuilder => $commit
                    ->whereColumn('commits.user_id', 'notifications.recipient_id')
                    ->whereColumn('commits.status', 'notifications.type'))))
            ->withoutSilencedActors();
    }

    public function unread(): self
    {
        return $this->whereNull('read_at');
    }

    /**
     * Leaves out what actors the recipient mutes did, and anything from across a block. Moderation
     * notifications are their own actor, and nobody can mute or block themselves.
     */
    private function withoutSilencedActors(): self
    {
        $silenced = new SilencedActorQuery('notifications.recipient_id', 'notifications.actor_id');

        return $this->whereNotExists($silenced->mutes(...))->whereNotExists($silenced->blocks(...));
    }
}
