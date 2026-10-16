<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\UpdateNotificationsReadAt;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Queries\CommitBuilder;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class NotificationController
{
    /**
     * The notifications grouped and filtered by ?type= like the web does it. As on the web, listing them marks every
     * notification as read, whatever the filter, and read_at says when, so the ones read since then are the new ones.
     * A commit comes with its author and the commit it replies to, when the user may see it.
     */
    public function index(ListNotificationsRequest $request, #[CurrentUser] User $user, NotificationGroupsQuery $query, UpdateNotificationsReadAt $action): AnonymousResourceCollection
    {
        $readAt = $action->handle($user);

        $notifications = $query->paginate($user, $request->type(), $request->through());

        /** @var array<int, Notification> $items */
        $items = $notifications->items();

        new Collection($items)->loadMissing([
            'commit.user',
            'commit.parent' => fn (BelongsTo $query): BelongsTo => $query
                ->where(fn (CommitBuilder $query): CommitBuilder => $query->visibleTo($user))
                ->with('user'),
        ]);

        return NotificationResource::collection($notifications)->additional([
            'filters' => ['type' => $request->type()],
            'read_at' => $readAt->toIso8601ZuluString(),
        ]);
    }
}
