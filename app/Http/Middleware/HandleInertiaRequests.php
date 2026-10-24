<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Log;
use App\Enums\RepliesPerPage;
use App\Http\Requests\ListNotificationsRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\ConversationsQuery;
use App\Queries\LiveFollowingQuery;
use App\Queries\NotificationGroupsQuery;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

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
     * A deploy sends a page built for the previous version back for a full reload. That page already took the flashed
     * data off the session, so it is flashed again for the reload: the composer learns its commit went through.
     */
    public function onVersionChange(Request $request, Response $response): Response
    {
        /** @var array{flash?: array<string, mixed>} $page */
        $page = $response instanceof JsonResponse ? $response->getData(true) : [];

        $response = parent::onVersionChange($request, $response);

        if (($page['flash'] ?? []) !== []) {
            Inertia::flash($page['flash']);
        }

        return $response;
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
        $showsWhoToFollow = $user instanceof User && $request->cookie('who_to_follow') !== 'false';
        $context = match (true) {
            $request->routeIs('user.show') => $request->route('user'),
            $request->routeIs('commit.show', 'commit-photo.show') => $request->route('commit'),
            default => null,
        };
        $context = $context instanceof User || $context instanceof Commit ? $context : null;

        $hasAside = ! $request->routeIs('onboarding.show') && ! $request->is('operations', 'operations/*');

        $suggestedUsers = $user instanceof User && $showsWhoToFollow && $hasAside
            ? Inertia::defer(fn (): Collection => resolve(SuggestedUsersQuery::class)->get(
                $user,
                round: max(0, (int) $request->cookie('who_to_follow_round')),
                spares: 3,
                context: $context,
            ))
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'repository' => config('services.github.repository'),
            'auth' => [
                'user' => fn (): ?User => $request->user()?->loadCounts(),
                'isAdmin' => $isAdmin,
                'automaticTranslation' => $user->automatic_translation ?? true,
                'mustReadCommitRules' => $user instanceof User && $user->commit_rules_accepted_at === null,
                'log' => fn (): ?Log => $request->user()?->log,
                'unreadNotificationsCount' => fn (): int => $user instanceof User ? resolve(NotificationGroupsQuery::class)->unreadCount($user, ListNotificationsRequest::rememberedType($request, $user)) : 0,
                'unreadMessagesCount' => fn (): int => $user?->remotes_enabled ? resolve(ConversationsQuery::class)->unreadCount($user) : 0,
                'remotes' => fn (): ?array => $user?->remotes_enabled ? [
                    'from' => $user->remotes_from,
                    'pull_receipts' => $user->pull_receipts,
                    'requests' => (bool) config('remotes.requests'),
                    'everyone' => (bool) config('remotes.everyone'),
                ] : null,
                'pasteis' => fn (): ?array => $user?->pasteis_mode ? [
                    'remaining' => $user->pasteisRemaining(),
                    'day' => today()->toDateString(),
                ] : null,
            ],
            'hasUnmoderatedCommits' => fn (): bool => $isAdmin && Commit::query()->pending()->exists(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'showsWhoToFollow' => $showsWhoToFollow,
            'dimsLanes' => $request->cookie('lane_dimming') !== 'false',
            'repliesPerPage' => RepliesPerPage::of($request)->value,
            // Guests follow nobody. The log's poll reloads just this, and a closure only runs when it is asked for.
            ...$user instanceof User && $hasAside ? [
                'liveFollowing' => fn (): Collection => resolve(LiveFollowingQuery::class)->get($user),
            ] : [],
            // Onboarding and the operations console have no aside. Contextual suggestions must not reuse the once-cached list across pages.
            ...$suggestedUsers !== null ? [
                'suggestedUsers' => ($context === null ? $suggestedUsers->once() : $suggestedUsers)->merge()->matchOn('id'),
            ] : [],
        ];
    }
}
