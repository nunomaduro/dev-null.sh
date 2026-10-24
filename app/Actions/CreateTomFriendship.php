<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateTomFriendship
{
    /**
     * Not numeric, so no GitHub account can ever own it and sign in as Tom.
     */
    public const string GITHUB_ID = 'tom';

    public const string USERNAME = 'tom';

    public const string FALLBACK_USERNAME = 'devnull-tom';

    /**
     * Like on MySpace, Tom is everyone's first friend: he follows them and they follow him, without a notification
     * either way, since nobody chose it.
     */
    public function handle(User $user): void
    {
        $tom = $this->tom();

        if ($user->is($tom)) {
            return;
        }

        DB::transaction(function () use ($user, $tom): void {
            $user->following()->syncWithoutDetaching([$tom->id]);
            $tom->following()->syncWithoutDetaching([$user->id]);
        });
    }

    /**
     * Tom only talks in public, replying to mentions, so he keeps remotes off: nobody can push to him or add him to
     * an org.
     */
    private function tom(): User
    {
        $tom = User::query()->firstWhere('github_id', self::GITHUB_ID);

        if ($tom instanceof User) {
            return $tom;
        }

        return DB::transaction(function (): User {
            $username = User::query()->where('username', self::USERNAME)->exists()
                ? self::FALLBACK_USERNAME
                : self::USERNAME;

            $tom = User::query()->create([
                'github_id' => self::GITHUB_ID,
                'username' => $username,
                'name' => 'Tom',
                'avatar_url' => asset('images/tom.webp'),
                'bio' => "I'm here to help you get started on dev/null.",
                'location' => 'Santa Monica, CA',
                'onboarded_at' => now(),
                'remotes_enabled' => false,
            ]);

            $tom->commits()->create([
                'body' => 'Welcome to dev/null!',
                'status' => CommitStatus::Approved,
            ]);

            return $tom;
        });
    }
}
