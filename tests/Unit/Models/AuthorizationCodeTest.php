<?php

declare(strict_types=1);

use App\Models\AuthorizationCode;
use App\Models\User;

test('to array', function (): void {
    $code = AuthorizationCode::factory()->create()->refresh();

    expect(array_keys($code->toArray()))
        ->toBe([
            'id',
            'user_id',
            'fingerprint',
            'device_name',
            'code_challenge',
            'redirect_uri',
            'expires_at',
            'created_at',
            'updated_at',
        ]);
});

it('belongs to a user, and is deleted with them', function (): void {
    $user = User::factory()->create();
    $code = AuthorizationCode::factory()->for($user)->create();

    expect($code->user->is($user))->toBeTrue()
        ->and($user->authorizationCodes()->sole()->is($code))->toBeTrue();

    $user->delete();

    expect($code->fresh())->toBeNull();
});

it('makes the pkce s256 challenge of a verifier', function (): void {
    // The example in RFC 7636, appendix B...
    expect(AuthorizationCode::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});
