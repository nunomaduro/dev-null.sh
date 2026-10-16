<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteUser;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ShowUserRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\PinnedCommitQuery;
use App\Queries\UserAchievementsQuery;
use App\Queries\UserActivityQuery;
use App\Queries\UserCommitsQuery;
use App\Queries\UserStreakQuery;
use App\Services\PageMetadata;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final readonly class UserController
{
    /**
     * Profiles are public, so guests and crawlers read the same page, with the profile's link preview.
     */
    public function show(ShowUserRequest $request, #[CurrentUser] ?User $currentUser, User $user, UserActivityQuery $activity, UserStreakQuery $streaks, UserAchievementsQuery $achievements, UserCommitsQuery $commits, PinnedCommitQuery $pinned, PageMetadata $metadata): Response
    {
        $blockerId = $currentUser?->blockerIdWith($user);

        if ($blockerId !== null) {
            return Inertia::render('user/unavailable', [
                'username' => $user->username,
                'blockedUser' => $blockerId === $currentUser->id ? $user : null,
            ]);
        }

        $streakData = null;
        $getStreaks = function () use (&$streakData, $streaks, $user, $currentUser): array {
            return $streakData ??= $streaks->get($user, $currentUser);
        };

        return Inertia::render('user/show', [
            'user' => fn (): User => $currentUser instanceof User ? $user->loadCounts($currentUser) : $user->loadPublicCounts(),
            'isFollowing' => fn (): bool => $currentUser?->isFollowing($user) ?? false,
            'followsYou' => fn (): bool => $currentUser instanceof User && $user->isFollowing($currentUser),
            'isMuting' => fn (): bool => $currentUser?->isMuting($user) ?? false,
            'activity' => fn (): array => $activity->get($user, $currentUser),
            'streaks' => $getStreaks,
            'achievements' => fn (): array => $achievements->get($user, $currentUser, $getStreaks()),
            'tab' => $request->tab(...),
            'pinnedCommit' => fn (): ?Commit => $request->tab() === 'replies' ? null : $pinned->get($user, $currentUser),
            'commits' => Inertia::scroll(fn () => $commits->builder($user, $currentUser, $request->tab() === 'replies')
                ->cursorPaginate(20)
                ->appends(['tab' => $request->tab()])),
        ])->withViewData(['preview' => $currentUser instanceof User ? null : $metadata->user($user->loadPublicCounts())]);
    }

    public function destroy(DeleteUserRequest $request, #[CurrentUser] User $user, DeleteUser $action): RedirectResponse
    {
        Auth::logout();

        $action->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('home');
    }
}
