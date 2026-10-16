<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;

it('lists the approved devices of the user, last approved first', function (): void {
    $user = User::factory()->create();

    $older = Device::factory()->for($user)->create([
        'name' => 'devnull on iPad',
        'approved_at' => now()->subWeek(),
        'last_used_at' => now()->subDay(),
    ]);
    $newer = Device::factory()->for($user)->create(['name' => 'devnull on iPhone']);

    Device::factory()->create();

    $response = $this->actingAs($user)->get(route('device.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('device/index')
            ->has('devices', 2)
            ->where('devices.0', [
                'id' => $newer->id,
                'fingerprint' => $newer->fingerprint,
                'name' => 'devnull on iPhone',
                'approved_at' => now()->startOfSecond()->toJSON(),
                'last_used_at' => null,
            ])
            ->where('devices.1.id', $older->id)
            ->where('devices.1.last_used_at', now()->subDay()->startOfSecond()->toJSON()));
});

it('lists no devices', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('device.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('device/index')
            ->has('devices', 0));
});

it('revokes a device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->fromRoute('device.index')
        ->delete(route('device.destroy', $device));

    $response->assertRedirectToRoute('device.index');

    expect($device->fresh())->toBeNull();
});

it("never revokes someone else's device", function (): void {
    $device = Device::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->delete(route('device.destroy', $device));

    $response->assertNotFound();

    expect($device->fresh())->not->toBeNull();
});

it('requires sign in', function (string $method, Closure $uri): void {
    $device = Device::factory()->create();

    $this->{$method}($uri($device))->assertRedirectToRoute('home');

    expect($device->fresh())->not->toBeNull();
})->with([
    'the list' => ['get', fn (Device $device): string => route('device.index')],
    'revoking' => ['delete', fn (Device $device): string => route('device.destroy', $device)],
]);
