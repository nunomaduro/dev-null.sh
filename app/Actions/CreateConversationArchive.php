<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

final readonly class CreateConversationArchive
{
    /**
     * Archiving is the user's own: their partner keeps the conversation in their inbox.
     */
    public function handle(User $user, User $partner): void
    {
        ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('pair', Conversation::pairOf($user, $partner)))
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }
}
