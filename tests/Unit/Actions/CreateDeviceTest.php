<?php

declare(strict_types=1);

use App\Actions\CreateDevice;
use App\Models\AuthorizationCode;
use App\Models\Device;
use App\Models\User;

it('approves the key of an authorization code as a device of its user, and uses the code up', function (): void {
    $user = User::factory()->create();
    $code = AuthorizationCode::factory()->for($user)->create(['fingerprint' => 'SHA256:key', 'device_name' => 'devnull on iPhone']);

    $device = resolve(CreateDevice::class)->handle($code);

    expect($device?->refresh()->user_id)->toBe($user->id)
        ->and($device?->fingerprint)->toBe('SHA256:key')
        ->and($device?->name)->toBe('devnull on iPhone')
        ->and($device?->approved_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($device?->last_used_at)->toBeNull()
        ->and($code->fresh())->toBeNull();
});

it('moves a key approved by someone else to the user who approved it now', function (): void {
    $user = User::factory()->create();
    $previous = Device::factory()->create(['fingerprint' => 'SHA256:key', 'last_used_at' => now()->subDay(), 'approved_at' => now()->subWeek()]);
    $code = AuthorizationCode::factory()->for($user)->create(['fingerprint' => 'SHA256:key', 'device_name' => 'devnull on Pixel']);

    $device = resolve(CreateDevice::class)->handle($code);

    expect($device?->is($previous))->toBeTrue()
        ->and($device?->refresh()->user_id)->toBe($user->id)
        ->and($device?->name)->toBe('devnull on Pixel')
        ->and($device?->approved_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($device?->last_used_at)->toBeNull()
        ->and(Device::query()->count())->toBe(1);
});

it('approves nothing for a code that was used or expired since it was checked', function (Closure $change): void {
    $code = AuthorizationCode::factory()->create();

    $change($code);

    expect(resolve(CreateDevice::class)->handle($code))->toBeNull()
        ->and(Device::query()->exists())->toBeFalse();
})->with([
    'used' => fn (AuthorizationCode $code) => $code->delete(),
    'expired' => fn () => test()->travel(AuthorizationCode::EXPIRES_AFTER_MINUTES)->minutes(),
]);
