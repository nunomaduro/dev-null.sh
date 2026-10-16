<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use App\Models\SshKey;
use App\Models\User;
use App\Services\SshSignatures;
use Illuminate\Support\Facades\Http;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->keyPair = SshKeyPair::generate();
    $this->user = User::factory()->create();

    SshKey::factory()->for($this->user)->withPublicKey($this->keyPair->publicKey)->create();

    fakeGitHubSshKeys($this->user, [$this->keyPair->publicKey]);
});

it('returns the key that signed the message', function (): void {
    $keyPair = SshKeyPair::generate();

    $publicKey = resolve(SshSignatures::class)->verify('It was DNS.', $keyPair->sign('It was DNS.', 'devnull-api'), 'devnull-api');

    expect($publicKey->fingerprint())->toBe($keyPair->publicKey->fingerprint());
});

it('verifies signatures made by ssh-keygen', function (): void {
    $publicKey = resolve(SshSignatures::class)->verify(sshFixture('message'), sshFixture('ed25519.sig'), 'devnull-commit');

    expect($publicKey->fingerprint())->toBe(SshPublicKey::fromOpenSsh(sshFixture('ed25519.pub'))->fingerprint());
});

it('rejects a signature over another message', function (): void {
    $signature = SshKeyPair::generate()->sign('It was DNS.', 'devnull-api');

    expect(fn (): SshPublicKey => resolve(SshSignatures::class)->verify('It was not DNS.', $signature, 'devnull-api'))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects a signature for another namespace', function (): void {
    $signature = SshKeyPair::generate()->sign('It was DNS.', 'devnull-commit');

    expect(fn (): SshPublicKey => resolve(SshSignatures::class)->verify('It was DNS.', $signature, 'devnull-api'))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects malformed signatures', function (): void {
    expect(fn (): SshPublicKey => resolve(SshSignatures::class)->verify('It was DNS.', 'not a signature', 'devnull-api'))
        ->toThrow(InvalidArgumentException::class, 'Malformed SSH signature.');
});

it('finds the user whose github account has the key', function (): void {
    $user = resolve(SshSignatures::class)->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), bin2hex(random_bytes(16)));

    expect($user?->is($this->user))->toBeTrue();
});

it('accepts small clock differences', function (int $offset): void {
    $user = resolve(SshSignatures::class)->authenticate($this->keyPair->publicKey, (string) (now()->getTimestamp() + $offset), bin2hex(random_bytes(16)));

    expect($user?->is($this->user))->toBeTrue();
})->with([-300, 300]);

it('rejects old, future or non-numeric timestamps', function (string $timestamp): void {
    expect(fn (): ?User => resolve(SshSignatures::class)->authenticate($this->keyPair->publicKey, $timestamp, bin2hex(random_bytes(16))))
        ->toThrow(InvalidArgumentException::class, 'Signature expired. Check that your clock is right.');
})->with([
    'old' => fn (): string => (string) (now()->getTimestamp() - 301),
    'future' => fn (): string => (string) (now()->getTimestamp() + 301),
    'non-numeric' => 'soon',
    'missing' => '',
]);

it('rejects malformed nonces', function (string $nonce): void {
    expect(fn (): ?User => resolve(SshSignatures::class)->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), $nonce))
        ->toThrow(InvalidArgumentException::class, 'Malformed payload.');
})->with([
    'too short' => 'abc123',
    'uppercase' => mb_strtoupper(str_repeat('ab', 16)),
    'missing' => '',
]);

it('spends the nonce once it finds the user', function (): void {
    $nonce = bin2hex(random_bytes(16));
    $sshSignatures = resolve(SshSignatures::class);

    $sshSignatures->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), $nonce);

    expect(fn (): ?User => $sshSignatures->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), $nonce))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');
});

it('returns null for keys that are not on the github account of a user, without spending the nonce', function (): void {
    $keyPair = SshKeyPair::generate();
    $nonce = bin2hex(random_bytes(16));
    $sshSignatures = resolve(SshSignatures::class);

    expect($sshSignatures->authenticate($keyPair->publicKey, (string) now()->getTimestamp(), $nonce))->toBeNull();

    $user = User::factory()->create();
    SshKey::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();
    fakeGitHubSshKeys($user, [$keyPair->publicKey]);

    expect($sshSignatures->authenticate($keyPair->publicKey, (string) now()->getTimestamp(), $nonce)?->is($user))->toBeTrue();
});

it('returns null for keys removed from github since the last sync', function (): void {
    $keyPair = SshKeyPair::generate();
    $user = User::factory()->create();
    $sshKey = SshKey::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();

    fakeGitHubSshKeys($user);

    expect(resolve(SshSignatures::class)->authenticate($keyPair->publicKey, (string) now()->getTimestamp(), bin2hex(random_bytes(16))))->toBeNull()
        ->and($sshKey->fresh())->toBeNull();
});

it('checks github at most every 5 minutes', function (): void {
    $sshSignatures = resolve(SshSignatures::class);

    $sshSignatures->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), bin2hex(random_bytes(16)));
    $sshSignatures->authenticate($this->keyPair->publicKey, (string) now()->getTimestamp(), bin2hex(random_bytes(16)));

    Http::assertSentCount(3);
});

it('checks the freshness of a signature whoever signed it, and spends its nonce', function (): void {
    $nonce = bin2hex(random_bytes(16));
    $sshSignatures = resolve(SshSignatures::class);

    $sshSignatures->checkFreshness((string) now()->getTimestamp(), $nonce);

    expect(fn () => $sshSignatures->checkFreshness((string) now()->getTimestamp(), $nonce))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');

    Http::assertNothingSent();
});

it('rejects a stale timestamp or a malformed nonce when checking freshness', function (string $timestamp, string $nonce, string $message): void {
    expect(fn () => resolve(SshSignatures::class)->checkFreshness($timestamp, $nonce))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an old timestamp' => [fn (): string => (string) (now()->getTimestamp() - 301), str_repeat('a', 32), 'Signature expired. Check that your clock is right.'],
    'a malformed nonce' => [fn (): string => (string) now()->getTimestamp(), 'nonce', 'Malformed payload.'],
]);
