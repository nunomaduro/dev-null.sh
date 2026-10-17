<?php

declare(strict_types=1);

use App\Dto\SshSignature;
use phpseclib4\Common\Functions\Strings;
use Tests\Support\SshKeyPair;

it('verifies signatures made by ssh-keygen', function (string $key): void {
    $signature = SshSignature::parse(sshFixture("{$key}.sig"));

    expect($signature->namespace)->toBe('devnull-commit')
        ->and($signature->publicKey->toOpenSsh().' fixture'."\n")->toBe(sshFixture("{$key}.pub"))
        ->and($signature->verify(sshFixture('message'), 'devnull-commit'))->toBeTrue();
})->with(['ed25519', 'rsa']);

it('re-encodes OpenSSH fixtures as portable armored receipts', function (string $key): void {
    $signature = SshSignature::parse(sshFixture("{$key}.sig"));
    $armored = $signature->toArmored();
    $roundTrip = SshSignature::parse($armored);

    expect($armored)->toStartWith('-----BEGIN SSH SIGNATURE-----')
        ->toContain('-----END SSH SIGNATURE-----')
        ->and($roundTrip->publicKey->fingerprint())->toBe($signature->publicKey->fingerprint())
        ->and($roundTrip->verify(sshFixture('message'), 'devnull-commit'))->toBeTrue()
        ->and($roundTrip->verify('Tampered payload', 'devnull-commit'))->toBeFalse();
})->with(['ed25519', 'rsa']);

it('rejects a tampered message', function (string $key): void {
    $signature = SshSignature::parse(sshFixture("{$key}.sig"));

    expect($signature->verify('It was not DNS.', 'devnull-commit'))->toBeFalse();
})->with(['ed25519', 'rsa']);

it('rejects another namespace', function (): void {
    $signature = SshSignature::parse(sshFixture('ed25519.sig'));

    expect($signature->verify(sshFixture('message'), 'git'))->toBeFalse();
});

it('parses the base64 body on a single line', function (): void {
    $keyPair = SshKeyPair::generate();

    $signature = SshSignature::parse($keyPair->sign('Hello'));

    expect($signature->publicKey->fingerprint())->toBe($keyPair->publicKey->fingerprint())
        ->and($signature->verify('Hello', 'devnull-commit'))->toBeTrue();
});

it('rejects signatures made by another key', function (): void {
    $blob = (string) base64_decode(SshKeyPair::generate()->sign('Hello'), true);
    $otherBlob = (string) base64_decode(SshKeyPair::generate()->sign('Hello'), true);

    $publicKeyLength = 4 + 4 + 51;
    $forged = mb_substr($blob, 0, 10, '8bit').mb_substr($otherBlob, 10, $publicKeyLength, '8bit').mb_substr($blob, 10 + $publicKeyLength, null, '8bit');

    expect(SshSignature::parse(base64_encode($forged))->verify('Hello', 'devnull-commit'))->toBeFalse();
});

it('rejects malformed signatures', function (string $signature, string $message): void {
    expect(fn (): SshSignature => SshSignature::parse($signature))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty' => ['', 'Malformed SSH signature.'],
    'not base64' => ['%%%', 'Malformed SSH signature.'],
    'bad magic' => [base64_encode('NOTSIG'), 'Malformed SSH signature.'],
    'truncated' => [base64_encode('SSHSIG'), 'Malformed SSH signature.'],
    'unknown version' => [base64_encode('SSHSIG'.Strings::packSSH2('Nsssss', 2, '', '', '', 'sha512', '')), 'Unsupported SSH signature version.'],
]);

it('rejects unsupported hash algorithms', function (): void {
    $blob = (string) base64_decode(SshKeyPair::generate()->sign('Hello'), true);

    $tampered = str_replace(Strings::packSSH2('s', 'sha512'), Strings::packSSH2('s', 'sha1xx'), $blob);

    expect(fn (): SshSignature => SshSignature::parse(base64_encode($tampered)))
        ->toThrow(InvalidArgumentException::class, 'Unsupported SSH signature hash algorithm.');
});

it('rejects trailing data', function (): void {
    $blob = (string) base64_decode(SshKeyPair::generate()->sign('Hello'), true);

    expect(fn (): SshSignature => SshSignature::parse(base64_encode($blob.'x')))
        ->toThrow(InvalidArgumentException::class, 'Malformed SSH signature.');
});
