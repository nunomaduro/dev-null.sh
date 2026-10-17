<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;
use phpseclib4\Common\Functions\Strings;
use phpseclib4\Exception\BaseException;

/**
 * A signature produced by `ssh-keygen -Y sign` (the SSHSIG format).
 *
 * @see https://github.com/openssh/openssh-portable/blob/master/PROTOCOL.sshsig
 */
final readonly class SshSignature
{
    private const string Magic = 'SSHSIG';

    private function __construct(
        public SshPublicKey $publicKey,
        public string $namespace,
        private string $reserved,
        private string $hashAlgorithm,
        private string $signature,
    ) {
        //
    }

    /**
     * Parses an armored signature, or its base64 body on a single line.
     */
    public static function parse(string $signature): self
    {
        $blob = base64_decode((string) preg_replace(['/-----(BEGIN|END) SSH SIGNATURE-----/', '/\s+/'], '', $signature), true);

        throw_if($blob === false || ! str_starts_with($blob, self::Magic), InvalidArgumentException::class, 'Malformed SSH signature.');

        $data = mb_substr($blob, mb_strlen(self::Magic, '8bit'), null, '8bit');

        try {
            [$version, $publicKey, $namespace, $reserved, $hashAlgorithm, $bytes] = Strings::unpackSSH2('Nsssss', $data);
        } catch (BaseException) {
            throw new InvalidArgumentException('Malformed SSH signature.');
        }

        throw_unless(
            $data === '' && is_string($publicKey) && is_string($namespace) && is_string($reserved) && is_string($hashAlgorithm) && is_string($bytes),
            InvalidArgumentException::class,
            'Malformed SSH signature.',
        );
        throw_if($version !== 1, InvalidArgumentException::class, 'Unsupported SSH signature version.');
        throw_unless(in_array($hashAlgorithm, ['sha256', 'sha512'], true), InvalidArgumentException::class, 'Unsupported SSH signature hash algorithm.');

        return new self(SshPublicKey::fromBlob($publicKey), $namespace, $reserved, $hashAlgorithm, $bytes);
    }

    /**
     * Return a standard OpenSSH-armored signature for independent verification.
     */
    public function toArmored(): string
    {
        $binary = self::Magic.Strings::packSSH2(
            'Nsssss',
            1,
            $this->publicKey->blob,
            $this->namespace,
            $this->reserved,
            $this->hashAlgorithm,
            $this->signature,
        );

        return "-----BEGIN SSH SIGNATURE-----\n"
            .chunk_split(base64_encode($binary), 70, "\n")
            ."-----END SSH SIGNATURE-----\n";
    }

    public function verify(string $message, string $namespace): bool
    {
        if ($this->namespace !== $namespace) {
            return false;
        }

        $signedData = self::Magic.Strings::packSSH2('ssss', $this->namespace, $this->reserved, $this->hashAlgorithm, hash($this->hashAlgorithm, $message, true));

        return $this->publicKey->verify($signedData, $this->signature);
    }
}
