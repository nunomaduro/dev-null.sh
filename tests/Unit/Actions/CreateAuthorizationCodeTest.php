<?php

declare(strict_types=1);

use App\Actions\CreateAuthorizationCode;
use App\Dto\MobileAuthorization;
use App\Models\AuthorizationCode;
use App\Models\User;

it('hands out a one-time code bound to what the user approved, and only keeps its hash', function (): void {
    $user = User::factory()->create();
    $authorization = new MobileAuthorization('SHA256:key', 'devnull on iPhone', 'challenge', 'state', 'devnull://auth/callback');

    $code = resolve(CreateAuthorizationCode::class)->handle($user, $authorization);

    $stored = AuthorizationCode::query()->sole();

    expect($code)->toMatch('/^[0-9a-f]{64}$/')
        ->and($stored->code_hash)->toBe(AuthorizationCode::hash($code))
        ->and($stored->code_hash)->not->toBe($code)
        ->and($stored->user_id)->toBe($user->id)
        ->and($stored->fingerprint)->toBe('SHA256:key')
        ->and($stored->device_name)->toBe('devnull on iPhone')
        ->and($stored->code_challenge)->toBe('challenge')
        ->and($stored->redirect_uri)->toBe('devnull://auth/callback')
        ->and($stored->expires_at->toDateTimeString())->toBe(now()->addMinutes(5)->toDateTimeString());
});

it('hands out a different code every time', function (): void {
    $user = User::factory()->create();
    $authorization = new MobileAuthorization('SHA256:key', 'devnull on iPhone', 'challenge', 'state', 'devnull://auth/callback');

    $first = resolve(CreateAuthorizationCode::class)->handle($user, $authorization);
    $second = resolve(CreateAuthorizationCode::class)->handle($user, $authorization);

    expect($first)->not->toBe($second)
        ->and(AuthorizationCode::query()->count())->toBe(2);
});
