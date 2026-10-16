<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\User;

final readonly class UpdateConversationPreferences
{
    public function handle(Conversation $conversation, User $user, bool $muted, bool $archived): void
    {
        $conversation->members()->where('user_id', $user->id)->update(['muted' => $muted, 'archived' => $archived]);
    }
}
