<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateDevice;
use App\Exceptions\ApiException;
use App\Http\Requests\CreateApiDeviceRequest;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final readonly class DeviceController
{
    /**
     * Approves the key that signed the request as a device, for the user who approved the app on the web, and answers
     * as GET /api/v1/me does. A code redeemed by another request in the meantime gets the same answer as a wrong one.
     */
    public function store(CreateApiDeviceRequest $request, CreateDevice $action, NotificationGroupsQuery $notifications): JsonResponse|UserResource
    {
        $device = $action->handle($request->authorizationCode());

        if (! $device instanceof Device) {
            return ApiException::invalidGrant()->render();
        }

        Auth::setUser($device->user);

        return UserResource::make($device->user->loadCounts())->additional([
            'unread_notifications_count' => $notifications->unreadCount($device->user),
        ]);
    }
}
