<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RemoteStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class CreateMessage
{
    /**
     * Sending the same client id again, as a retry does, returns the message it already created. A first message
     * lands in the recipient's open conversations, or fetched as a request when they only take remotes from people
     * they follow. A new message brings the conversation back out of the stash for both people.
     */
    public function handle(User $sender, User $recipient, string $body, string $clientId): Message
    {
        $message = DB::transaction(function () use ($sender, $recipient, $body, $clientId): Message {
            $conversation = Conversation::query()->createOrFirst(['pair' => Conversation::pairOf($sender, $recipient)]);

            ConversationParticipant::query()->createOrFirst(
                ['conversation_id' => $conversation->id, 'user_id' => $sender->id],
            );

            ConversationParticipant::query()->createOrFirst(
                ['conversation_id' => $conversation->id, 'user_id' => $recipient->id],
                ['status' => $recipient->acceptsRemotesFrom($sender) ? RemoteStatus::Open : RemoteStatus::Fetched],
            );

            $message = Message::query()->createOrFirst(
                ['user_id' => $sender->id, 'client_id' => $clientId],
                ['conversation_id' => $conversation->id, 'body' => $body],
            );

            if (! $message->wasRecentlyCreated) {
                return $message;
            }

            Conversation::query()
                ->whereKey($conversation->id)
                ->where(fn (Builder $query): Builder => $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
                ->update(['last_message_id' => $message->id]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->update(['last_message_at' => $message->created_at, 'archived_at' => null]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['status' => RemoteStatus::Open]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->where('read_through_id', '<', $message->id)
                ->update(['read_through_id' => $message->id, 'read_at' => now()]);

            return $message;
        });

        Cache::forget(UpdateConversationTyping::key($sender, $recipient));

        return $message;
    }
}
