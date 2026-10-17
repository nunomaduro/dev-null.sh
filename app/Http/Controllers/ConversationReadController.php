<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateConversationReadThrough;
use App\Http\Requests\UpdateConversationReadRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ConversationReadController
{
    public function update(UpdateConversationReadRequest $request, #[CurrentUser] User $user, User $partner, UpdateConversationReadThrough $action): Response
    {
        $action->handle($user, $partner, $request->integer('through'));

        return response()->noContent();
    }
}
