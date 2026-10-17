<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class NotificationGroupsQuery
{
    public const int PER_PAGE = 20;

    private const int STACKED_ACTORS = 3;

    /** @return CursorPaginator<int, Notification> */
    public function paginate(User $user, string $type = 'all', ?CarbonImmutable $through = null): CursorPaginator
    {
        $order = 'PARTITION BY group_id ORDER BY CASE WHEN actor_position = 1 THEN 0 ELSE 1 END, created_at DESC, id DESC
            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING';
        $events = $this->events($user, $type)->select('notifications.*')
            ->selectRaw("NTH_VALUE(actor_id, 2) OVER ({$order}) AS second_actor_id")
            ->selectRaw("NTH_VALUE(actor_id, 3) OVER ({$order}) AS third_actor_id");

        $query = Notification::query()->fromSub($events, 'notifications')
            ->where('group_rank', 1)
            ->with([
                'actor' => fn (BelongsTo $query): BelongsTo => $query
                    ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($user->id)]),
                'commit',
            ])
            ->latest()->latest('id');
        $perPage = $through instanceof CarbonImmutable
            ? max(self::PER_PAGE, $this->groupsSince($user, $through, $type)->where('group_rank', 1)->count())
            : self::PER_PAGE;

        $groups = $query->cursorPaginate($perPage)->appends(request()->except('through'));

        $this->loadStackedActors($groups->items());

        return $groups;
    }

    /**
     * Whether two unread notifications share a group only depends on what happened between them, so grouping from the
     * oldest unread one onward counts the same groups as grouping the whole history, which every page would pay for.
     */
    public function unreadCount(User $user): int
    {
        $oldestUnread = $this->visible($user)->unread()->oldest()->first();

        return $oldestUnread instanceof Notification
            ? $this->groupsSince($user, $oldestUnread->created_at)->where('group_rank', 1)->where('unread_count', '>', 0)->count()
            : 0;
    }

    public function members(User $user, Notification $notification, string $type = 'all'): NotificationBuilder
    {
        return $this->group($this->visible($user), $type, $notification)
            ->with(['actor' => fn (BelongsTo $query): BelongsTo => $query
                ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($user->id)])]);
    }

    public function events(User $user, string $type = 'all'): NotificationBuilder
    {
        return $this->group($this->visible($user), $type);
    }

    /**
     * Grouping only the notifications from some point onwards puts every one of them in the same group as grouping all
     * of them does, apart from the oldest group of each topic missing what came before that point. So it finds just as
     * many groups with notifications from then on.
     */
    private function groupsSince(User $user, CarbonInterface $since, string $type = 'all'): NotificationBuilder
    {
        return $this->group($this->visible($user)->where('created_at', '>=', $since), $type);
    }

    private function visible(User $user): NotificationBuilder
    {
        return Notification::query()->where('recipient_id', $user->id)->relevant()->select('notifications.*');
    }

    private function group(NotificationBuilder $visible, string $type = 'all', ?Notification $member = null): NotificationBuilder
    {
        $filtered = Notification::query()->from('visible')->select('visible.*', 'identified.group_id')
            ->join('identified', 'identified.id', '=', 'visible.id')
            ->when($member, fn (NotificationBuilder $query, Notification $member): NotificationBuilder => $query->where(
                'identified.group_id',
                fn (QueryBuilder $query): QueryBuilder => $query->from('identified')->where('id', $member->id)->select('group_id'),
            ))
            ->when($type !== 'all', fn (NotificationBuilder $query): NotificationBuilder => $query->whereIn('type', match ($type) {
                'replies' => ['replied'],
                'quotes' => ['quoted'],
                'stars' => ['starred'],
                'mentions' => ['mentioned'],
                'follows' => ['followed'],
                'moderation' => ['approved', 'rejected'],
                default => [],
            }));

        $gap = match (DB::connection($visible->getModel()->getConnectionName())->getDriverName()) {
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(SECOND, previous_match_at, created_at)',
            'pgsql' => 'EXTRACT(EPOCH FROM (created_at - previous_match_at))',
            default => 'unixepoch(created_at) - unixepoch(previous_match_at)',
        };

        // Deleted ancestors supply grouping identity only; content comes from relevant notifications. A commit's
        // recorded root is always one of its ancestors, so the walk up the thread only continues past it when the
        // recorded root is itself a reply.
        $sql = <<<SQL
            WITH RECURSIVE visible AS ({$visible->toSql()}),
            threads AS (
                SELECT visible.id AS notification_id, roots.id, roots.parent_id
                FROM visible JOIN commits ON commits.id = visible.commit_id
                JOIN commits AS roots ON roots.id = COALESCE(commits.root_id, commits.id)
                WHERE visible.type IN ('replied', 'mentioned')
            ),
            ancestry AS (
                SELECT threads.notification_id, parent.id, parent.parent_id
                FROM threads JOIN commits AS parent ON parent.id = threads.parent_id
                UNION ALL
                SELECT ancestry.notification_id, parent.id, parent.parent_id
                FROM ancestry JOIN commits AS parent ON parent.id = ancestry.parent_id
            ),
            topics AS (
                SELECT visible.*,
                    CASE WHEN visible.type = 'starred' THEN 'starred' ELSE 'activity' END AS topic_kind,
                    CASE WHEN visible.type = 'followed' THEN visible.recipient_id
                        WHEN visible.type = 'starred' THEN visible.commit_id
                        ELSE COALESCE(ancestry.id, threads.id, visible.id) END AS topic_id
                FROM visible LEFT JOIN threads ON threads.notification_id = visible.id
                LEFT JOIN ancestry ON ancestry.notification_id = visible.id AND ancestry.parent_id IS NULL
            ),
            predecessors AS (
                SELECT id, topic_kind, topic_id, created_at,
                    LAG(created_at) OVER (PARTITION BY topic_kind, topic_id ORDER BY created_at, id) AS previous_match_at,
                    LAG(topic_kind) OVER (ORDER BY created_at, id) AS previous_topic_kind,
                    LAG(topic_id) OVER (ORDER BY created_at, id) AS previous_topic_id
                FROM topics
            ),
            sessions AS (
                SELECT id, topic_kind, topic_id, created_at,
                    SUM(CASE WHEN topic_kind = 'starred' THEN 0
                        WHEN previous_match_at IS NULL
                        OR ({$gap} > 3600 AND (previous_topic_kind <> topic_kind OR previous_topic_id <> topic_id))
                        THEN 1 ELSE 0 END)
                        OVER (PARTITION BY topic_kind, topic_id ORDER BY created_at, id ROWS UNBOUNDED PRECEDING) AS session_number
                FROM predecessors
            ),
            identified AS (
                SELECT id,
                    FIRST_VALUE(id) OVER (PARTITION BY topic_kind, topic_id, session_number ORDER BY created_at, id) AS group_id
                FROM sessions
            ),
            filtered AS (
                {$filtered->toSql()}
            ),
            ranked_actors AS (
                SELECT filtered.*,
                    ROW_NUMBER() OVER (PARTITION BY group_id, actor_id ORDER BY created_at DESC, id DESC) AS actor_position
                FROM filtered
            )
            SELECT ranked_actors.*,
                ROW_NUMBER() OVER (PARTITION BY group_id ORDER BY created_at DESC, id DESC) AS group_rank,
                COUNT(*) OVER (PARTITION BY group_id) AS event_count,
                SUM(CASE WHEN actor_position = 1 THEN 1 ELSE 0 END) OVER (PARTITION BY group_id) AS actors_count,
                SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) OVER (PARTITION BY group_id) AS unread_count
            FROM ranked_actors
            SQL;

        return Notification::query()->fromSub($sql, 'notifications')->addBinding($visible->getBindings(), 'from')
            ->addBinding($filtered->getBindings(), 'from')
            ->withCasts(['event_count' => 'integer']);
    }

    /** @param array<int, Notification> $groups */
    private function loadStackedActors(array $groups): void
    {
        $grouped = array_filter($groups, fn (Notification $group): bool => $group->actors_count > 1);
        $actors = $grouped === [] ? new Collection() : User::query()
            ->findMany(array_merge(...array_map($this->stackedActorIds(...), $grouped)));

        foreach ($groups as $group) {
            $groupActors = $group->actors_count === 1 ? [$group->actor] : array_filter(array_map(
                $actors->find(...),
                $this->stackedActorIds($group),
            ));

            $group->setRelation('actors', new Collection(array_values($groupActors)));
        }
    }

    /**
     * The window lists the newest notification of each actor first, so past the group's actors it repeats them.
     *
     * @return list<string>
     */
    private function stackedActorIds(Notification $group): array
    {
        return array_slice(
            array_values(array_filter([$group->actor_id, $group->second_actor_id, $group->third_actor_id])),
            0,
            min(self::STACKED_ACTORS, $group->actors_count),
        );
    }
}
