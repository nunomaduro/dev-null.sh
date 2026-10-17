<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateMessage;
use App\Http\Requests\CreateConversationMessageRequest;
use App\Http\Requests\ListConversationMessagesRequest;
use App\Models\User;
use App\Queries\ConversationThreadQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class ConversationMessageController
{
    public function index(ListConversationMessagesRequest $request, #[CurrentUser] User $user, User $partner, ConversationThreadQuery $threads): JsonResponse
    {
        return response()->json([
            'partner' => $partner,
            ...$threads->get($user, $partner, $request->afterId(), $request->beforeId()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(CreateConversationMessageRequest $request, #[CurrentUser] User $user, User $partner, CreateMessage $action): JsonResponse
    {
        $message = $action->handle($user, $partner, $request->string('body')->value(), $request->string('client_id')->value());

        return response()->json(['message' => $message], 201);
    }
}
