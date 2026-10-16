<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Leaves banned users out of every query, so they can't sign in, any session of theirs is signed out, and nobody finds
 * them. Their account stays as it is, and lifting the ban brings it back.
 *
 * @implements Scope<User>
 */
final readonly class WithoutBannedUsers implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNotIn($builder->qualifyColumn('github_id'), fn (QueryBuilder $bans): QueryBuilder => $bans
            ->select('github_id')
            ->from('bans'));
    }
}
