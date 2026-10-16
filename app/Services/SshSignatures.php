<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\UpdateUserSshKeys;
use App\Dto\SshPublicKey;
use App\Dto\SshSignature;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The checks shared by everything signed with a user's SSH key, like commits from the `devnull commit` script.
 */
final readonly class SshSignatures
{
    private const int MaxClockSkewSeconds = 300;

    public function __construct(private UpdateUserSshKeys $updateUserSshKeys)
    {
        //
    }

    /**
     * Verifies a message signed with `ssh-keygen -Y sign`, and returns the key that signed it.
     */
    public function verify(string $message, string $signature, string $namespace): SshPublicKey
    {
        $sshSignature = SshSignature::parse($signature);

        throw_unless($sshSignature->verify($message, $namespace), InvalidArgumentException::class, 'Invalid signature.');

        return $sshSignature->publicKey;
    }

    /**
     * Finds the devnull user who signed with the key, from the timestamp and nonce signed along with it.
     *
     * A signature expires a few minutes after its timestamp, and is only good once: its nonce is spent when it finds
     * the user. Returns null when the key is not on the GitHub account of a devnull user.
     */
    public function authenticate(SshPublicKey $publicKey, string $timestamp, string $nonce): ?User
    {
        $this->ensureRecent($timestamp, $nonce);

        $user = $this->findKeyOwner($publicKey);

        if (! $user instanceof User) {
            return null;
        }

        $this->spendNonce($nonce);

        return $user;
    }

    /**
     * Checks the timestamp and nonce signed along with a message and spends the nonce, whoever the key belongs to: a
     * signature expires a few minutes after its timestamp, and is only good once.
     */
    public function checkFreshness(string $timestamp, string $nonce): void
    {
        $this->ensureRecent($timestamp, $nonce);

        $this->spendNonce($nonce);
    }

    /**
     * Finds the user whose GitHub account has the key, re-checking GitHub every few minutes so that
     * a key removed from GitHub stops working shortly after.
     */
    private function findKeyOwner(SshPublicKey $publicKey): ?User
    {
        $user = User::query()->whereRelation('sshKeys', 'fingerprint', $publicKey->fingerprint())->first();

        if ($user === null) {
            return null;
        }

        $this->updateUserSshKeys->handle($user, unlessRecentlySynced: true);

        return $user->sshKeys()->where('fingerprint', $publicKey->fingerprint())->exists() ? $user : null;
    }

    private function ensureRecent(string $timestamp, string $nonce): void
    {
        throw_unless(
            ctype_digit($timestamp) && abs(now()->getTimestamp() - (int) $timestamp) <= self::MaxClockSkewSeconds,
            InvalidArgumentException::class,
            'Signature expired. Check that your clock is right.',
        );

        throw_unless(preg_match('/^[0-9a-f]{32}$/', $nonce) === 1, InvalidArgumentException::class, 'Malformed payload.');
    }

    private function spendNonce(string $nonce): void
    {
        throw_unless(
            // Remembered a bit longer than a signature can be valid (from -skew to +skew around its timestamp)...
            Cache::add("ssh-signature-nonce:{$nonce}", true, self::MaxClockSkewSeconds * 2 + 60),
            InvalidArgumentException::class,
            'This signature was already used.',
        );
    }
}
