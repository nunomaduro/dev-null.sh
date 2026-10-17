<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Query\Builder;

/**
 * Subqueries for `whereNotExists` that match when the recipient mutes the actor, blocks the actor, or is blocked by
 * the actor. The two block directions stay separate subqueries so each one is a lookup on the blocks primary key.
 */
final readonly class SilencedActorQuery
{
    public function __construct(private string $recipientColumn, private string $actorColumn)
    {
        //
    }

    public function mutes(Builder $query): Builder
    {
        return $query->from('mutes')
            ->whereColumn('mutes.muter_id', $this->recipientColumn)
            ->whereColumn('mutes.muted_id', $this->actorColumn);
    }

    public function blocking(Builder $query): Builder
    {
        return $query->from('blocks')
            ->whereColumn('blocks.blocker_id', $this->recipientColumn)
            ->whereColumn('blocks.blocked_id', $this->actorColumn);
    }

    public function blockers(Builder $query): Builder
    {
        return $query->from('blocks')
            ->whereColumn('blocks.blocker_id', $this->actorColumn)
            ->whereColumn('blocks.blocked_id', $this->recipientColumn);
    }
}
