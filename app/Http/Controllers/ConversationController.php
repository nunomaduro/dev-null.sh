<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationReadThrough;
use App\Http\Requests\ListConversationsRequest;
use App\Http\Requests\ShowConversationRequest;
use App\Models\User;
use App\Queries\ConversationsQuery;
use App\Queries\ConversationThreadQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ConversationController
{
    public function index(ListConversationsRequest $request, #[CurrentUser] User $user, ConversationsQuery $conversations): Response
    {
        return Inertia::render('conversation/index', [
            'inbox' => fn (): array => $conversations->get($user),
            'partner' => null,
            'thread' => null,
        ]);
    }

    /**
     * The thread is read before it is marked read, so the page can show where the user left off.
     */
    public function show(ShowConversationRequest $request, #[CurrentUser] User $user, User $partner, ConversationsQuery $conversations, ConversationThreadQuery $threads, UpdateConversationReadThrough $action): Response
    {
        $thread = $threads->get($user, $partner);

        $action->handle($user, $partner, $thread['messages']->last()->id ?? 0);

        return Inertia::render('conversation/index', [
            'inbox' => fn (): array => $conversations->get($user),
            'partner' => $partner,
            'thread' => $thread,
        ]);
    }
}
