<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateSuggestionDismissal;
use App\Http\Requests\CreateApiSuggestionDismissalRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class SuggestionDismissalController
{
    /**
     * Not interested, as on the web: they are never suggested again, and nothing else changes.
     */
    public function store(CreateApiSuggestionDismissalRequest $request, #[CurrentUser] User $currentUser, User $user, CreateSuggestionDismissal $action): Response
    {
        $action->handle($currentUser, $user);

        return response()->noContent();
    }
}
