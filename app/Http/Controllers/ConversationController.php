<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateConversationRequest;
use App\Http\Requests\ListConversationsRequest;
use App\Http\Requests\ShowConversationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Queries\DirectMessagingQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ConversationController
{
    public function index(ListConversationsRequest $request, #[CurrentUser] User $user, DirectMessagingQuery $query): Response
    {
        return Inertia::render('messages/index', [
            'conversations' => fn () => $query->inbox($user, $request->folder(), $request->search())
                ->through(fn (Conversation $conversation): array => $query->summary($conversation, $user)),
            'filters' => ['folder' => $request->folder(), 'search' => $request->search()],
            'enabled' => $user->dm_policy !== 'off',
        ]);
    }

    public function create(CreateConversationRequest $request, #[CurrentUser] User $user, DirectMessagingQuery $query): Response
    {
        $recipient = $request->recipient();
        $existing = $recipient instanceof User ? $query->between($user, $recipient) : null;

        return Inertia::render('messages/create', [
            'recipient' => $recipient,
            'canSend' => $recipient instanceof User && $query->canSend($user, $recipient, $existing),
            'existing' => $recipient instanceof User && ! $user->hasBlockWith($recipient) ? $existing?->id : null,
            'enabled' => $user->dm_policy !== 'off',
        ]);
    }

    public function show(ShowConversationRequest $request, #[CurrentUser] User $user, Conversation $conversation, DirectMessagingQuery $query): Response
    {
        return Inertia::render('messages/show', [
            'conversation' => fn (): array => $query->summary($conversation, $user),
            'messages' => fn () => $conversation->messages()->orderByDesc('id')->cursorPaginate(50)->withQueryString(),
            'isOlderPage' => $request->filled('cursor'),
        ]);
    }
}
