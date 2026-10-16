<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationRequest;
use App\Http\Requests\UpdateConversationRequestRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class ConversationRequestController
{
    public function update(UpdateConversationRequestRequest $request, #[CurrentUser] User $user, Conversation $conversation, UpdateConversationRequest $action): RedirectResponse
    {
        $action->handle($conversation, $user, $request->string('status')->value());

        return back();
    }
}
