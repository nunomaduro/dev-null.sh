<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateNotificationsReadAt;
use App\Http\Requests\ListNotificationSyncRequest;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class NotificationSyncController
{
    public function index(
        ListNotificationSyncRequest $request,
        #[CurrentUser] User $user,
        NotificationGroupsQuery $query,
        UpdateNotificationsReadAt $action,
    ): JsonResponse {
        $action->handle($user);

        return response()->json([
            ...$query->sync($user, $request->type(), $request->visible()),
            'unread_notifications_count' => $query->unreadCount($user),
        ])->header('Cache-Control', 'private, no-store');
    }
}
