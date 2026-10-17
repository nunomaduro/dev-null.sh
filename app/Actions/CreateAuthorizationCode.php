<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\MobileAuthorization;
use App\Models\AuthorizationCode;
use App\Models\User;

final readonly class CreateAuthorizationCode
{
    /**
     * Hands out a one-time code for the devnull app to redeem for a device, bound to what the user approved. Only its
     * hash is kept, and it expires after a few minutes.
     */
    public function handle(User $user, MobileAuthorization $authorization): string
    {
        $code = bin2hex(random_bytes(32));

        $user->authorizationCodes()->create([
            'code_hash' => AuthorizationCode::hash($code),
            'fingerprint' => $authorization->fingerprint,
            'device_name' => $authorization->deviceName,
            'code_challenge' => $authorization->codeChallenge,
            'redirect_uri' => $authorization->redirectUri,
            'expires_at' => now()->addMinutes(AuthorizationCode::EXPIRES_AFTER_MINUTES),
        ]);

        return $code;
    }
}
