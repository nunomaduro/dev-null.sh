<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;

test('to array', function (): void {
    $device = Device::factory()->create()->refresh();

    expect(array_keys($device->toArray()))
        ->toBe([
            'id',
            'fingerprint',
            'name',
            'approved_at',
            'last_used_at',
        ]);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();

    expect($device->user->is($user))->toBeTrue();
});

it('is deleted with its user', function (): void {
    $device = Device::factory()->create();

    $device->user->delete();

    expect($device->fresh())->toBeNull();
});
