<?php

declare(strict_types=1);

use App\Models\SshKey;
use App\Models\User;
use App\Services\SignedCommits;
use Illuminate\Support\Facades\Http;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->keyPair = SshKeyPair::generate();
    $this->user = User::factory()->create();

    SshKey::factory()->for($this->user)->withPublicKey($this->keyPair->publicKey)->create();

    fakeGitHubSshKeys($this->user, [$this->keyPair->publicKey]);
});

it('verifies a signed commit', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, "  It was DNS.\n\nIt is always DNS.\n");

    $commit = resolve(SignedCommits::class)->verify($payload, $signature);

    expect($commit['user']->is($this->user))->toBeTrue()
        ->and($commit['reply_to'])->toBeNull()
        ->and($commit['body'])->toBe("It was DNS.\n\nIt is always DNS.");
});

it('verifies a signed reply', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'Agreed!', replyTo: '0be0130');

    $commit = resolve(SignedCommits::class)->verify($payload, $signature);

    expect($commit['reply_to'])->toBe('0be0130')
        ->and($commit['body'])->toBe('Agreed!');
});

it('verifies a signed commit with images', function (): void {
    $hashes = [hash('sha256', 'first image'), hash('sha256', 'second image')];

    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, "Shipped!\n\nWith pictures.", imageHashes: $hashes);

    $commit = resolve(SignedCommits::class)->verify($payload, $signature, fn (): array => $hashes);

    expect($commit['user']->is($this->user))->toBeTrue()
        ->and($commit['reply_to'])->toBeNull()
        ->and($commit['body'])->toBe("Shipped!\n\nWith pictures.");
});

it('rejects images that do not match the signed payload', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'Shipped!', imageHashes: [hash('sha256', 'signed image')]);

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature, fn (): array => [hash('sha256', 'tampered image')]))
        ->toThrow(InvalidArgumentException::class, 'The attached images do not match the signed payload.');
});

it('rejects malformed image hashes without hashing the images', function (string $hashes): void {
    $payload = now()->getTimestamp()."\n".bin2hex(random_bytes(16))."\n\n{$hashes}\nShipped!";

    expect(fn (): array => resolve(SignedCommits::class)->verify(
        $payload,
        $this->keyPair->sign($payload, SignedCommits::ImagesNamespace),
        fn (): array => throw new RuntimeException('The images were hashed.'),
    ))->toThrow(InvalidArgumentException::class, 'Malformed payload.');
})->with([
    'no hash' => '',
    'not a hash' => 'not-a-hash',
    'uppercase' => mb_strtoupper(hash('sha256', 'image')),
    'more than 3' => implode(',', array_fill(0, 4, hash('sha256', 'image'))),
]);

it('does not hash the images of a key that is not on the github account of a user', function (): void {
    $keyPair = SshKeyPair::generate();

    ['payload' => $payload, 'signature' => $signature] = signedCommit($keyPair, 'Shipped!', imageHashes: [hash('sha256', 'image')]);

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature, fn (): array => throw new RuntimeException('The images were hashed.')))
        ->toThrow(InvalidArgumentException::class, "This SSH key ({$keyPair->publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.");
});

it('does not hash the images of a replayed signature', function (): void {
    $hashes = [hash('sha256', 'image')];

    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'Shipped!', imageHashes: $hashes);

    $signedCommits = resolve(SignedCommits::class);

    $signedCommits->verify($payload, $signature, fn (): array => $hashes);

    expect(fn (): array => $signedCommits->verify($payload, $signature, fn (): array => throw new RuntimeException('The images were hashed.')))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');
});

it('rejects images attached to a commit signed without them', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'Shipped!');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature, fn (): array => [hash('sha256', 'unsigned image')]))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects a commit with images replayed without them', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'Shipped!', imageHashes: [hash('sha256', 'stripped image')]);

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects an invalid signature', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'It was DNS.');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload.'!', $signature))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects a signature for another namespace', function (): void {
    ['payload' => $payload] = signedCommit($this->keyPair, 'It was DNS.');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $this->keyPair->sign($payload, 'git')))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects malformed payloads', function (string $payload): void {
    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $this->keyPair->sign($payload)))
        ->toThrow(InvalidArgumentException::class, 'Malformed payload.');
})->with([
    'missing body' => fn (): string => now()->getTimestamp()."\n".bin2hex(random_bytes(16))."\n",
    'invalid nonce' => fn (): string => now()->getTimestamp()."\nnonce\n\nIt was DNS.",
    'invalid utf-8' => fn (): string => now()->getTimestamp()."\n".bin2hex(random_bytes(16))."\n\nIt was \xff DNS.",
]);

it('rejects old or future timestamps', function (int $offset): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'It was DNS.', timestamp: now()->getTimestamp() + $offset);

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, 'Signature expired. Check that your clock is right.');
})->with([-301, 301]);

it('accepts small clock differences', function (int $offset): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'It was DNS.', timestamp: now()->getTimestamp() + $offset);

    $commit = resolve(SignedCommits::class)->verify($payload, $signature);

    expect($commit['user']->is($this->user))->toBeTrue();
})->with([-300, 300]);

it('rejects a non-numeric timestamp', function (): void {
    $payload = "soon\n".bin2hex(random_bytes(16))."\n\nIt was DNS.";

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $this->keyPair->sign($payload)))
        ->toThrow(InvalidArgumentException::class, 'Signature expired. Check that your clock is right.');
});

it('rejects keys that are not on the github account of a user', function (): void {
    $keyPair = SshKeyPair::generate();

    ['payload' => $payload, 'signature' => $signature] = signedCommit($keyPair, 'It was DNS.');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, "This SSH key ({$keyPair->publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.");
});

it('rejects a replayed signature', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'It was DNS.');

    $signedCommits = resolve(SignedCommits::class);

    $signedCommits->verify($payload, $signature);

    expect(fn (): array => $signedCommits->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');
});

it('rejects a replayed signature until it expires', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit($this->keyPair, 'It was DNS.', timestamp: now()->getTimestamp() + 300);

    $signedCommits = resolve(SignedCommits::class);

    $signedCommits->verify($payload, $signature);

    $this->travel(600)->seconds();

    expect(fn (): array => $signedCommits->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');

    $this->travel(1)->seconds();

    expect(fn (): array => $signedCommits->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, 'Signature expired. Check that your clock is right.');
});

it('rejects unknown keys without calling github', function (): void {
    ['payload' => $payload, 'signature' => $signature] = signedCommit(SshKeyPair::generate(), 'It was DNS.');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects keys removed from github since the last sync', function (): void {
    $keyPair = SshKeyPair::generate();
    $user = User::factory()->create();
    $sshKey = SshKey::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();

    fakeGitHubSshKeys($user);

    ['payload' => $payload, 'signature' => $signature] = signedCommit($keyPair, 'It was DNS.');

    expect(fn (): array => resolve(SignedCommits::class)->verify($payload, $signature))
        ->toThrow(InvalidArgumentException::class, "This SSH key ({$keyPair->publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.")
        ->and($sshKey->fresh())->toBeNull();
});

it('checks github at most every 5 minutes', function (): void {
    $signedCommits = resolve(SignedCommits::class);

    $signedCommits->verify(...signedCommit($this->keyPair, 'First'));
    $signedCommits->verify(...signedCommit($this->keyPair, 'Second'));

    Http::assertSentCount(3);
});
