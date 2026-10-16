<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateDirectMessage
{
    public function handle(User $sender, User $recipient, string $body, string $clientId): Conversation
    {
        return DB::transaction(function () use ($sender, $recipient, $body, $clientId): Conversation {
            $ids = [$sender->id, $recipient->id];
            sort($ids);
            $conversation = Conversation::query()->firstOrCreate([
                'first_user_id' => $ids[0], 'second_user_id' => $ids[1],
            ], [
                'initiator_id' => $sender->id,
                'status' => $recipient->isFollowing($sender) ? 'accepted' : 'requested',
                'last_message_at' => now(),
            ]);
            foreach ($ids as $id) {
                $conversation->members()->firstOrCreate(['user_id' => $id]);
            }

            $message = DirectMessage::query()->firstOrCreate([
                'sender_id' => $sender->id, 'client_id' => $clientId,
            ], ['conversation_id' => $conversation->id, 'body' => $body, 'created_at' => now()]);

            if (! $message->wasRecentlyCreated) {
                return $conversation;
            }

            $conversation->update(['last_message_at' => $message->created_at]);
            $conversation->members()->update(['archived' => false]);
            $member = $conversation->members()->where('user_id', $recipient->id)->sole();

            if (! $member->muted && ! $recipient->isMuting($sender)) {
                Notification::query()->updateOrCreate([
                    'recipient_id' => $recipient->id, 'key' => 'conversation:'.$conversation->id,
                ], [
                    'actor_id' => $sender->id,
                    'type' => $conversation->status === 'requested' ? NotificationType::MessageRequested : NotificationType::Messaged,
                    'conversation_id' => $conversation->id,
                    'read_at' => null,
                    'created_at' => now(),
                ]);
            }

            return $conversation;
        });
    }
}
