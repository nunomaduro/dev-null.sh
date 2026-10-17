<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

final readonly class UpdateConversationReadThrough
{
    /**
     * Only ever moves forward, and never past the conversation's last message.
     */
    public function handle(User $user, User $partner, int $through): void
    {
        $conversation = Conversation::query()->where('pair', Conversation::pairOf($user, $partner))->first();

        if (! $conversation instanceof Conversation || $conversation->last_message_id === null) {
            return;
        }

        $through = min($through, $conversation->last_message_id);

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where('read_through_id', '<', $through)
            ->update(['read_through_id' => $through, 'read_at' => now()]);
    }
}
