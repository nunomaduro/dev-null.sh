<?php

declare(strict_types=1);

use App\Actions\UpdateUserTimezone;
use App\Models\User;

it('may choose a timezone', function (): void {
    $user = User::factory()->create();

    $action = resolve(UpdateUserTimezone::class);

    $action->handle($user, 'America/New_York');

    expect($user->refresh()->timezone)->toBe('America/New_York');
});

it('may clear the timezone', function (): void {
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $action = resolve(UpdateUserTimezone::class);

    $action->handle($user, null);

    expect($user->refresh()->timezone)->toBeNull();
});
