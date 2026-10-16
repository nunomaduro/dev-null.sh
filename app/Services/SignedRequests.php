<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\UpdateDeviceLastUsedAt;
use App\Dto\SshPublicKey;
use App\Models\Device;
use Illuminate\Http\Request;

/**
 * Verifies a request to the API, signed by the mobile app with an SSH key it keeps on the device.
 *
 * The signed message is "<unix timestamp>\n<nonce>\n<METHOD>\n<path and query>\n<body>". The timestamp and nonce
 * are also sent in the X-Devnull-Timestamp and X-Devnull-Nonce headers, and the signature in X-SSH-Signature.
 *
 * A key signs in as a user once that user approved it as a device on the web. The key doesn't have to be on their
 * GitHub account: the approval is the proof. So a key made for something else, like committing from CI, can't sign in.
 */
final readonly class SignedRequests
{
    public const string Namespace = 'devnull-api';

    private const array Headers = ['X-Devnull-Timestamp', 'X-Devnull-Nonce', 'X-SSH-Signature'];

    public function __construct(private SshSignatures $sshSignatures, private UpdateDeviceLastUsedAt $updateDeviceLastUsedAt)
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
     * Checks the signature, its timestamp and its nonce, which it spends, and returns the key that signed the request,
     * whether or not it is approved as a device yet.
     */
    public function verifySignature(Request $request): SshPublicKey
    {
        $timestamp = $request->headers->get('X-Devnull-Timestamp', '') ?? '';
        $nonce = $request->headers->get('X-Devnull-Nonce', '') ?? '';

        $publicKey = $this->sshSignatures->verify(
            implode("\n", [$timestamp, $nonce, $request->getMethod(), $request->getRequestUri(), $request->getContent()]),
            $request->headers->get('X-SSH-Signature', '') ?? '',
            self::Namespace,
        );

        $this->sshSignatures->checkFreshness($timestamp, $nonce);

        return $publicKey;
    }

    /**
     * The device is null when nobody approved the key that signed the request. Using a device counts as its last use.
     *
     * @return array{publicKey: SshPublicKey, device: Device|null}
     */
    public function verify(Request $request): array
    {
        $publicKey = $this->verifySignature($request);

        $device = Device::query()->with('user')->where('fingerprint', $publicKey->fingerprint())->first();

        if ($device instanceof Device) {
            $this->updateDeviceLastUsedAt->handle($device);
        }

        return [
            'publicKey' => $publicKey,
            'device' => $device,
        ];
    }
}
