<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\Mentions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class MentionedUsersQuery
{
    public function __construct(private Mentions $mentions)
    {
        //
    }

    /**
     * @return array<string, string>
     */
    public function get(string $body): array
    {
        $usernames = $this->mentions->usernames($body);

        if ($usernames === []) {
            return [];
        }

        return User::query()
            ->whereIn(DB::raw('LOWER(username)'), $usernames)
            ->get(['id', 'username'])
            ->mapWithKeys(fn (User $user): array => [Str::lower($user->username) => $user->id])
            ->all();
    }
}
