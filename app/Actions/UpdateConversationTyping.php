<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final readonly class UpdateConversationTyping
{
    /**
     * Long enough to bridge the composer's pings and the partner's polls, short enough to vanish soon after typing stops.
     */
    public const int SECONDS = 5;

    public static function key(User $user, User $partner): string
    {
        return 'conversation-typing:'.Conversation::pairOf($user, $partner).':'.$user->id;
    }

    public function handle(User $user, User $partner): void
    {
        Cache::put(self::key($user, $partner), true, self::SECONDS);
    }
}
