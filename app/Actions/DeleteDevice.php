<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;

final readonly class DeleteDevice
{
    /**
     * Revokes the device: the app on it can't sign in until it is approved again.
     */
    public function handle(Device $device): void
    {
        $device->delete();
    }
}
