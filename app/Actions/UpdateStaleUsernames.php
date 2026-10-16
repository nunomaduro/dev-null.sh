<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Scopes\WithoutBannedUsers;
use App\Models\User;

final readonly class UpdateStaleUsernames
{
    /**
     * GitHub usernames are unique case-insensitively, so another user holding this one renamed away on GitHub. The
     * placeholder starts with an underscore, which no GitHub username can, and their next sign-in restores a real one.
     * Banned users still hold their username, so they're renamed too.
     */
    public function handle(string $username, string $githubId): void
    {
        User::query()
            ->withoutGlobalScope(WithoutBannedUsers::class)
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
            ->where('github_id', '!=', $githubId)
            ->each(function (User $user): void {
                $user->update(['username' => '_renamed-'.$user->github_id]);
            });
    }
}
