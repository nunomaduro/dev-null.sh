<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ShowApiUserRequest;
use App\Http\Resources\CommitResource;
use App\Http\Resources\UserResource;
use App\Models\Commit;
use App\Models\User;
use App\Queries\PinnedCommitQuery;
use App\Queries\ReplyPreviewsQuery;
use App\Queries\UserAchievementsQuery;
use App\Queries\UserActivityQuery;
use App\Queries\UserStreakQuery;
use Illuminate\Container\Attributes\CurrentUser;

final readonly class UserController
{
    /**
     * A signed request gets the profile as the web shows it to that user, with whether they mute it, and its
     * achievements and Unix rank. The pinned commit is left out of the commits list, so it comes with the profile, as
     * on the web.
     */
    public function show(ShowApiUserRequest $request, #[CurrentUser] ?User $currentUser, User $user, UserActivityQuery $activity, UserStreakQuery $streaks, UserAchievementsQuery $achievements, PinnedCommitQuery $pinned, ReplyPreviewsQuery $replyPreviews): UserResource
    {
        if ($currentUser instanceof User) {
            $pinnedCommit = $pinned->get($user, $currentUser);

            $replyPreviews->load(array_filter([$pinnedCommit]), $currentUser);

            $userStreaks = $streaks->get($user, $currentUser);

            return UserResource::make($user->loadCounts($currentUser))->additional([
                'is_following' => $currentUser->isFollowing($user),
                'follows_you' => $user->isFollowing($currentUser),
                'is_muting' => $currentUser->isMuting($user),
                'activity' => $activity->get($user, $currentUser),
                'streaks' => $userStreaks,
                'achievements' => $achievements->get($user, $currentUser, $userStreaks),
                'pinned_commit' => $pinnedCommit instanceof Commit ? CommitResource::make($pinnedCommit) : null,
            ]);
        }

        return UserResource::make($user->loadPublicCounts());
    }
}
