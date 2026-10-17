<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateConversationArchive;
use App\Actions\DeleteConversationArchive;
use App\Http\Requests\CreateConversationArchiveRequest;
use App\Http\Requests\DeleteConversationArchiveRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ConversationArchiveController
{
    public function store(CreateConversationArchiveRequest $request, #[CurrentUser] User $user, User $partner, CreateConversationArchive $action): Response
    {
        $action->handle($user, $partner);

        return response()->noContent();
    }

    public function destroy(DeleteConversationArchiveRequest $request, #[CurrentUser] User $user, User $partner, DeleteConversationArchive $action): Response
    {
        $action->handle($user, $partner);

        return response()->noContent();
    }
}
