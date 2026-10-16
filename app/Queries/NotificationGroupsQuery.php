<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

final readonly class NotificationGroupsQuery
{
    public const int PER_PAGE = 20;

    private const int STACKED_ACTORS = 3;

    /** @return CursorPaginator<int, Notification> */
    public function paginate(User $user, string $type = 'all', ?CarbonImmutable $through = null): CursorPaginator
    {
        $query = $this->events($user, $type)
            ->where('group_rank', 1)
            ->with([
                'actor' => fn (BelongsTo $query): BelongsTo => $query
                    ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($user->id)]),
                'commit',
            ])
            ->latest()->latest('id');
        $perPage = $through instanceof CarbonImmutable
            ? max(self::PER_PAGE, (clone $query)->where('created_at', '>=', $through)->count())
            : self::PER_PAGE;

        $groups = $query->cursorPaginate($perPage)->appends(request()->except('through'));

        $this->loadStackedActors($user, $type, $groups->items());

        return $groups;
    }

    public function unreadCount(User $user): int
    {
        return $this->events($user)->where('group_rank', 1)->where('unread_count', '>', 0)->count();
    }

    public function members(User $user, Notification $notification, string $type = 'all'): NotificationBuilder
    {
        $group = $this->events($user)->whereKey($notification->id)->sole();

        return $this->events($user, $type)->where('group_id', $group->group_id)
            ->with(['actor' => fn (BelongsTo $query): BelongsTo => $query
                ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($user->id)])]);
    }

    public function events(User $user, string $type = 'all'): NotificationBuilder
    {
        $visible = Notification::query()->where('recipient_id', $user->id)->relevant()->select('notifications.*');
        $filtered = Notification::query()->from('visible')->select('visible.*', 'identified.group_id')
            ->join('identified', 'identified.id', '=', 'visible.id')
            ->when($type !== 'all', fn (NotificationBuilder $query): NotificationBuilder => $query->whereIn('type', match ($type) {
                'messages' => ['messaged', 'message_requested'],
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

        // Deleted ancestors supply grouping identity only; content comes from relevant notifications.
        $sql = <<<SQL
            WITH RECURSIVE visible AS ({$visible->toSql()}),
            ancestry AS (
                SELECT visible.id AS notification_id, commits.id, commits.parent_id
                FROM visible JOIN commits ON commits.id = visible.commit_id
                WHERE visible.type IN ('replied', 'mentioned')
                UNION ALL
                SELECT ancestry.notification_id, parent.id, parent.parent_id
                FROM ancestry JOIN commits AS parent ON parent.id = ancestry.parent_id
            ),
            topics AS (
                SELECT visible.*,
                    CASE WHEN visible.type = 'starred' THEN 'starred' ELSE 'activity' END AS topic_kind,
                    CASE WHEN visible.type = 'followed' THEN visible.recipient_id
                        WHEN visible.type = 'starred' THEN visible.commit_id
                        ELSE COALESCE(ancestry.id, visible.id) END AS topic_id
                FROM visible LEFT JOIN ancestry ON ancestry.notification_id = visible.id AND ancestry.parent_id IS NULL
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
    private function loadStackedActors(User $user, string $type, array $groups): void
    {
        $grouped = array_filter($groups, fn (Notification $group): bool => $group->actors_count > 1);
        $actors = $this->events($user, $type)
            ->whereIn('group_id', array_map(fn (Notification $group): string => $group->group_id, $grouped))
            ->where('actor_position', 1)
            ->select('notifications.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY group_id ORDER BY created_at DESC, id DESC) AS group_actor_position');

        $stacked = $grouped === [] ? new Collection() : Notification::query()
            ->fromSub($actors, 'notifications')
            ->where('group_actor_position', '<=', self::STACKED_ACTORS)
            ->with('actor')->orderBy('group_actor_position')->get();

        foreach ($groups as $group) {
            $groupActors = $group->actors_count === 1 ? [$group->actor] : $stacked
                ->filter(fn (Notification $notification): bool => $notification->group_id === $group->group_id)
                ->map(fn (Notification $notification): User => $notification->actor)->values()->all();

            $group->setRelation('actors', new Collection($groupActors));
        }
    }
}
