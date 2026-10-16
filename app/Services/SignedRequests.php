<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\SshPublicKey;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Verifies a request to the API, signed by the mobile app with an SSH key it keeps on the device.
 *
 * The signed message is "<unix timestamp>\n<nonce>\n<METHOD>\n<path and query>\n<body>". The timestamp and nonce
 * are also sent in the X-Devnull-Timestamp and X-Devnull-Nonce headers, and the signature in X-SSH-Signature.
 */
final readonly class SignedRequests
{
    public const string Namespace = 'devnull-api';

    private const array Headers = ['X-Devnull-Timestamp', 'X-Devnull-Nonce', 'X-SSH-Signature'];

    public function __construct(private SshSignatures $sshSignatures)
    {
        //
    }

    /**
     * Whether the request comes with any of the signature headers. It still has to pass verify() to be trusted.
     */
    public function isSigned(Request $request): bool
    {
        return array_any(self::Headers, fn (string $header): bool => $request->headers->has($header));
    }

    /**
     * The user is null when the key is not on the GitHub account of a devnull user.
     *
     * @return array{publicKey: SshPublicKey, user: User|null}
     */
    public function verify(Request $request): array
    {
        $timestamp = $request->headers->get('X-Devnull-Timestamp', '') ?? '';
        $nonce = $request->headers->get('X-Devnull-Nonce', '') ?? '';

        $publicKey = $this->sshSignatures->verify(
            implode("\n", [$timestamp, $nonce, $request->getMethod(), $request->getRequestUri(), $request->getContent()]),
            $request->headers->get('X-SSH-Signature', '') ?? '',
            self::Namespace,
        );

        return [
            'publicKey' => $publicKey,
            'user' => $this->sshSignatures->authenticate($publicKey, $timestamp, $nonce),
        ];
    }
}
