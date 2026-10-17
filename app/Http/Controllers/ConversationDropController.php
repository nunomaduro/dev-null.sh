<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationStatus;
use App\Enums\RemoteStatus;
use App\Http\Requests\CreateConversationDropRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ConversationDropController
{
    public function store(CreateConversationDropRequest $request, #[CurrentUser] User $user, User $partner, UpdateConversationStatus $action): Response
    {
        $action->handle($user, $partner, RemoteStatus::Dropped);

        return response()->noContent();
    }
}
