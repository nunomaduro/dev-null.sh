<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateConversationRead
{
    public function handle(Conversation $conversation, User $user, int $through): void
    {
        DB::transaction(function () use ($conversation, $user, $through): void {
            $conversation->members()->where('user_id', $user->id)->where('last_read_message_id', '<', $through)
                ->update(['last_read_message_id' => $through]);

            if (! $conversation->messages()->where('sender_id', '!=', $user->id)->where('id', '>', $through)->exists()) {
                Notification::query()->where('conversation_id', $conversation->id)->where('recipient_id', $user->id)
                    ->whereNull('read_at')->update(['read_at' => now()]);
            }
        });
    }
}
