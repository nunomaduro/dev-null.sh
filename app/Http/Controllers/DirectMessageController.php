<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDirectMessage;
use App\Http\Requests\CreateDirectMessageRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class DirectMessageController
{
    public function store(CreateDirectMessageRequest $request, #[CurrentUser] User $sender, User $user, CreateDirectMessage $action): RedirectResponse
    {
        $conversation = $action->handle($sender, $user, $request->body(), $request->clientId());

        Inertia::flash('directMessageSent', $request->clientId());

        return to_route('conversation.show', $conversation);
    }
}
