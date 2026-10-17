<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateUserFollow
{
    public function __construct(private CreateFollowNotification $createFollowNotification)
    {
        //
    }

    /**
     * Inserted unless already there rather than synced, which would read every follow the follower already has.
     */
    public function handle(User $follower, User $followed): void
    {
        DB::transaction(function () use ($follower, $followed): void {
            $follower->following()->newPivotStatement()->insertOrIgnore([
                'follower_id' => $follower->id,
                'followed_id' => $followed->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createFollowNotification->handle($follower, $followed);
        });
    }
}
