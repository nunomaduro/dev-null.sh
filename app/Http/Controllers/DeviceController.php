<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteDevice;
use App\Http\Requests\DeleteDeviceRequest;
use App\Models\Device;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class DeviceController
{
    /**
     * The devices that may use the signed API through the devnull app, each approved on the web from the app.
     */
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('device/index', [
            'devices' => $user->devices()->latest('approved_at')->get(),
        ]);
    }

    public function destroy(DeleteDeviceRequest $request, Device $device, DeleteDevice $action): RedirectResponse
    {
        $action->handle($device);

        return back();
    }
}
