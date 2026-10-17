<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;

/**
 * Verifies a commit signed with `ssh-keygen -Y sign` by the `devnull commit` script.
 *
 * The signed payload is "<unix timestamp>\n<nonce>\n<commit to reply to, or empty>\n<body>".
 */
final readonly class SignedCommits
{
    public const string Namespace = 'devnull-commit';

    public function __construct(private SshSignatures $sshSignatures)
    {
        //
    }

    /**
     * @return array{user: User, reply_to: string|null, body: string}
     */
    public function verify(string $payload, string $signature): array
    {
        $publicKey = $this->sshSignatures->verify($payload, $signature, self::Namespace);

        $parts = explode("\n", $payload, 4);

        throw_if(count($parts) !== 4 || ! mb_check_encoding($payload, 'UTF-8'), InvalidArgumentException::class, 'Malformed payload.');

        [$timestamp, $nonce, $replyTo, $body] = $parts;

        $user = $this->sshSignatures->authenticate($publicKey, $timestamp, $nonce);

        throw_if(
            ! $user instanceof User,
            InvalidArgumentException::class,
            "This SSH key ({$publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.",
        );

        return [
            'user' => $user,
            'reply_to' => $replyTo === '' ? null : $replyTo,
            'body' => mb_trim($body),
        ];
    }
}
