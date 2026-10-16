<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateConversationRequest
{
    public function handle(Conversation $conversation, User $user, string $status): void
    {
        DB::transaction(function () use ($conversation, $user, $status): void {
            $conversation->update(['status' => $status]);
            $conversation->members()->where('user_id', $user->id)->update(['archived' => $status === 'declined']);
            Notification::query()->where('conversation_id', $conversation->id)->delete();
        });
    }
}
