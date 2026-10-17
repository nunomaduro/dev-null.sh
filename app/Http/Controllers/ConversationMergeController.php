<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationStatus;
use App\Enums\RemoteStatus;
use App\Http\Requests\CreateConversationMergeRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ConversationMergeController
{
    public function store(CreateConversationMergeRequest $request, #[CurrentUser] User $user, User $partner, UpdateConversationStatus $action): Response
    {
        $action->handle($user, $partner, RemoteStatus::Open);

        return response()->noContent();
    }
}
