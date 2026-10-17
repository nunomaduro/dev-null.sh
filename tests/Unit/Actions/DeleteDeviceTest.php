<?php

declare(strict_types=1);

use App\Actions\DeleteDevice;
use App\Models\Device;

it('revokes a device', function (): void {
    $device = Device::factory()->create();
    $other = Device::factory()->for($device->user)->create();

    resolve(DeleteDevice::class)->handle($device);

    expect($device->fresh())->toBeNull()
        ->and($other->fresh())->not->toBeNull();
});
