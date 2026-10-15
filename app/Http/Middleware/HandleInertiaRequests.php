<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Commit;
use App\Models\User;
use App\Queries\OnlineDevelopersQuery;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $user = $request->user();
        $showsWhoToFollow = $request->cookie('who_to_follow') !== 'false';

        if ($user instanceof User) {
            if ($user->last_active_at === null || $user->last_active_at->diffInMinutes(now()) >= 1) {
                $user->forceFill(['last_active_at' => now()])->saveQuietly();
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn (): ?User => $request->user()?->loadCounts(),
                'isAdmin' => $isAdmin,
                'unreadNotificationsCount' => fn (): int => $request->user()?->notifications()->relevant()->unread()->count() ?? 0,
            ],
            'hasUnmoderatedCommits' => fn (): bool => $isAdmin && Commit::query()->pending()->exists(),
            'onlineDevelopersCount' => fn (): int => resolve(OnlineDevelopersQuery::class)->count(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'showsWhoToFollow' => $showsWhoToFollow,
            ...$user instanceof User && $showsWhoToFollow ? [
                'suggestedUsers' => Inertia::defer(fn (): Collection => resolve(SuggestedUsersQuery::class)->get($user)),
            ] : [],
        ];
    }
}
