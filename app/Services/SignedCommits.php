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
 * Verifies a commit signed with `ssh-keygen -Y sign` by the `devnull commit` script.
 *
 * The signed payload is "<unix timestamp>\n<nonce>\n<commit to reply to, or empty>\n<body>".
 */
final readonly class SignedCommits
{
    public const string Namespace = 'devnull-commit';

    private const int MaxClockSkewSeconds = 300;

    public function __construct(private UpdateUserSshKeys $updateUserSshKeys)
    {
        //
    }

    /**
     * @return array{user: User, reply_to: string|null, body: string, proof: array{payload: string, signature: string, public_key: string, fingerprint: string}}
     */
    public function verify(string $payload, string $signature): array
    {
        $sshSignature = SshSignature::parse($signature);

        throw_unless($sshSignature->verify($payload, self::Namespace), InvalidArgumentException::class, 'Invalid signature.');

        $parts = explode("\n", $payload, 4);

        throw_if(count($parts) !== 4 || ! mb_check_encoding($payload, 'UTF-8'), InvalidArgumentException::class, 'Malformed payload.');

        [$timestamp, $nonce, $replyTo, $body] = $parts;

        throw_unless(
            ctype_digit($timestamp) && abs(now()->getTimestamp() - (int) $timestamp) <= self::MaxClockSkewSeconds,
            InvalidArgumentException::class,
            'Signature expired. Check that your clock is right.',
        );

        throw_unless(preg_match('/^[0-9a-f]{32}$/', $nonce) === 1, InvalidArgumentException::class, 'Malformed payload.');

        $user = $this->findKeyOwner($sshSignature->publicKey);

        throw_if(
            ! $user instanceof User,
            InvalidArgumentException::class,
            "This SSH key ({$sshSignature->publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.",
        );

        throw_unless(
            // Remembered a bit longer than a signature can be valid (from -skew to +skew around its timestamp)...
            Cache::add("commit-nonce:{$nonce}", true, self::MaxClockSkewSeconds * 2 + 60),
            InvalidArgumentException::class,
            'This signature was already used.',
        );

        return [
            'user' => $user,
            'reply_to' => $replyTo === '' ? null : $replyTo,
            'body' => mb_trim($body),
            // Built only after cryptographic verification and GitHub key ownership checks.
            'proof' => [
                'payload' => $payload,
                'signature' => $signature,
                'public_key' => $sshSignature->publicKey->toOpenSsh(),
                'fingerprint' => $sshSignature->publicKey->fingerprint(),
            ],
        ];
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
}
