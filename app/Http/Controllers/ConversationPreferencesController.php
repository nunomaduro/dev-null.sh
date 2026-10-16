<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationPreferences;
use App\Http\Requests\UpdateConversationPreferencesRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class ConversationPreferencesController
{
    public function update(UpdateConversationPreferencesRequest $request, #[CurrentUser] User $user, Conversation $conversation, UpdateConversationPreferences $action): RedirectResponse
    {
        $action->handle($conversation, $user, $request->boolean('muted'), $request->boolean('archived'));

        return back();
    }
}
