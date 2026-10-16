<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AuthorizationCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one-time code the web hands the devnull app once the user approves it, for the app to redeem for a device. It
 * is bound to the key, the device name, the app's PKCE challenge and the redirect it was sent to, expires after a few
 * minutes, and is only kept as a hash.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string $code_hash
 * @property-read string $fingerprint
 * @property-read string $device_name
 * @property-read string $code_challenge
 * @property-read string $redirect_uri
 * @property-read CarbonInterface $expires_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
#[Hidden([
    'code_hash',
])]
final class AuthorizationCode extends Model
{
    /** @use HasFactory<AuthorizationCodeFactory> */
    use HasFactory;

    use HasUuids;

    public const int EXPIRES_AFTER_MINUTES = 5;

    /**
     * The hash a code is stored and found by. Codes are long and random, so a fast hash is enough.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * The PKCE S256 challenge for a verifier: base64url(sha256(verifier)), without padding.
     */
    public static function challengeFor(string $verifier): string
    {
        return mb_rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'code_hash' => 'string',
            'fingerprint' => 'string',
            'device_name' => 'string',
            'code_challenge' => 'string',
            'redirect_uri' => 'string',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
