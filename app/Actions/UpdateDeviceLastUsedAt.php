<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;

final readonly class UpdateDeviceLastUsedAt
{
    /**
     * Writes at most once a minute per device, rather than on every request the app makes.
     */
    public function handle(Device $device): void
    {
        if ($device->last_used_at?->isAfter(now()->subMinute()) === true) {
            return;
        }

        $device->update(['last_used_at' => now()]);
    }
}
