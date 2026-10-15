<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Commit;
use App\Models\User;

final readonly class Presenter
{
    /**
     * @return array<string, mixed>
     */
    public function commit(Commit $commit): array
    {
        if ($commit->trashed()) {
            return [
                'id' => $commit->id,
                'hash' => $this->hash($commit),
                'deleted' => true,
            ];
        }

        return [
            'id' => $commit->id,
            'hash' => $this->hash($commit),
            'url' => route('commit.show', [$commit->user, $commit]),
            'author' => $commit->user->username,
            'body' => $commit->body,
            'status' => $commit->status->value,
            'parent_id' => $commit->parent_id,
            'created_at' => $commit->created_at->toIso8601String(),
            'stars_count' => $this->loaded($commit, 'stars_count'),
            'replies_count' => $this->loaded($commit, 'replies_count'),
            'starred' => $this->loaded($commit, 'starred') === null ? null : (bool) $this->loaded($commit, 'starred'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function user(User $user): array
    {
        return [
            'username' => $user->username,
            'name' => $user->name,
            'bio' => $user->bio,
            'url' => route('user.show', $user),
        ];
    }

    /**
     * Counts and flags only exist on commits that were queried with them.
     */
    private function loaded(Commit $commit, string $attribute): mixed
    {
        return $commit->getAttributes()[$attribute] ?? null;
    }

    private function hash(Commit $commit): string
    {
        return mb_substr($commit->id, -7);
    }
}
