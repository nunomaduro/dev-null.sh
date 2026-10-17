<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RemoteStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

final readonly class UpdateConversationStatus
{
    /**
     * Merges a fetched request into the user's open conversations, or drops it.
     */
    public function handle(User $user, User $partner, RemoteStatus $status): void
    {
        ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('pair', Conversation::pairOf($user, $partner)))
            ->where('status', RemoteStatus::Fetched)
            ->update(['status' => $status]);
    }
}
