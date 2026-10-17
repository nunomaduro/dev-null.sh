<?php

declare(strict_types=1);

use App\Actions\UpdateDeviceLastUsedAt;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

it('records when the device was used, at most once a minute', function (): void {
    $device = Device::factory()->create();
    $action = resolve(UpdateDeviceLastUsedAt::class);

    $action->handle($device);

    expect($device->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    $usedAt = now();
    $this->travel(59)->seconds();

    DB::enableQueryLog();

    $action->handle($device);

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($device->refresh()->last_used_at?->toDateTimeString())->toBe($usedAt->toDateTimeString());

    $this->travel(1)->second();

    $action->handle($device);

    expect($device->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});
