<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationRead;
use App\Http\Requests\UpdateConversationReadRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class ConversationReadController
{
    public function update(UpdateConversationReadRequest $request, #[CurrentUser] User $user, Conversation $conversation, UpdateConversationRead $action): RedirectResponse
    {
        $action->handle($conversation, $user, $request->integer('through'));

        return back();
    }
}
