<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListApiSuggestedUsersRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class SuggestedUserController
{
    /**
     * Who to follow, as the web suggests them: three people to show and three spares to fill the place of one
     * dismissed, each with why they are suggested and whether they follow the user. Each round is the next page of
     * the day's picks, as the web's refresh button shows. Beside a profile or a commit, the people that developer
     * follows or the thread's participants come first, as the web suggests them there. Nobody suggested is followed
     * yet, which each one says, as in any other list of people.
     */
    public function index(ListApiSuggestedUsersRequest $request, #[CurrentUser] User $user, SuggestedUsersQuery $suggested): AnonymousResourceCollection
    {
        $suggestions = $suggested->get($user, round: $request->integer('round'), spares: 3, context: $request->context());

        $suggestions->each(function (User $suggestion): void {
            $suggestion->setAttribute('is_followed', false);
        });

        return UserResource::collection($suggestions);
    }
}
