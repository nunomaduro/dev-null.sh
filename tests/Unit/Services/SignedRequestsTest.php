<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SignedRequests;
use Illuminate\Http\Request;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('tells a request with any of the signature headers from one without', function (array $headers, bool $isSigned): void {
    $request = Request::create('https://devnull.test/api/v1/users/octocat', server: $headers);

    expect(resolve(SignedRequests::class)->isSigned($request))->toBe($isSigned);
})->with([
    'none' => [[], false],
    'other headers' => [['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer token'], false],
    'a timestamp' => [['HTTP_X_DEVNULL_TIMESTAMP' => '1791000000'], true],
    'a nonce' => [['HTTP_X_DEVNULL_NONCE' => str_repeat('0', 32)], true],
    'a signature' => [['HTTP_X_SSH_SIGNATURE' => ''], true],
]);

it('verifies a signed request', function (string $method, string $uri, string $body): void {
    $request = Request::create($uri, $method, server: signApiRequest($this->keyPair, $method, $uri, $body), content: $body);

    ['publicKey' => $publicKey, 'user' => $user] = resolve(SignedRequests::class)->verify($request);

    expect($publicKey->fingerprint())->toBe($this->keyPair->publicKey->fingerprint())
        ->and($user?->is($this->user))->toBeTrue();
})->with([
    'without a body' => ['GET', 'https://devnull.test/api/v1/log/latest', ''],
    'with a query' => ['GET', 'https://devnull.test/api/v1/log/latest?cursor=abc&x=%20y', ''],
    'with a body' => ['POST', 'https://devnull.test/api/v1/commits', '{"body":"It was DNS.","parent_id":null}'],
    'with a multi-line body' => ['POST', 'https://devnull.test/api/v1/commits', "{\"body\":\"It was DNS.\"}\n\n"],
]);

it('returns no user for keys that are not on the github account of a user', function (): void {
    $keyPair = SshKeyPair::generate();
    $uri = 'https://devnull.test/api/v1/me';

    $request = Request::create($uri, server: signApiRequest($keyPair, 'GET', $uri));

    ['publicKey' => $publicKey, 'user' => $user] = resolve(SignedRequests::class)->verify($request);

    expect($publicKey->fingerprint())->toBe($keyPair->publicKey->fingerprint())
        ->and($user)->toBeNull();
});

it('rejects a request that differs from the one that was signed', function (string $method, string $uri, string $body, array $headers): void {
    $server = signApiRequest($this->keyPair, 'POST', 'https://devnull.test/api/v1/commits?a=1', '{"body":"It was DNS."}');

    $request = Request::create($uri, $method, server: [...$server, ...$headers], content: $body);

    expect(fn (): array => resolve(SignedRequests::class)->verify($request))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
})->with([
    'method' => ['PUT', 'https://devnull.test/api/v1/commits?a=1', '{"body":"It was DNS."}', []],
    'path' => ['POST', 'https://devnull.test/api/v1/commitz?a=1', '{"body":"It was DNS."}', []],
    'query' => ['POST', 'https://devnull.test/api/v1/commits?a=2', '{"body":"It was DNS."}', []],
    'missing query' => ['POST', 'https://devnull.test/api/v1/commits', '{"body":"It was DNS."}', []],
    'body' => ['POST', 'https://devnull.test/api/v1/commits?a=1', '{"body":"It was not DNS."}', []],
    'timestamp' => ['POST', 'https://devnull.test/api/v1/commits?a=1', '{"body":"It was DNS."}', ['HTTP_X_DEVNULL_TIMESTAMP' => '1']],
    'nonce' => ['POST', 'https://devnull.test/api/v1/commits?a=1', '{"body":"It was DNS."}', ['HTTP_X_DEVNULL_NONCE' => str_repeat('0', 32)]],
]);

it('rejects a signature for another namespace', function (): void {
    $uri = 'https://devnull.test/api/v1/me';

    $request = Request::create($uri, server: signApiRequest($this->keyPair, 'GET', $uri, namespace: 'devnull-commit'));

    expect(fn (): array => resolve(SignedRequests::class)->verify($request))
        ->toThrow(InvalidArgumentException::class, 'Invalid signature.');
});

it('rejects a request without a signature', function (): void {
    expect(fn (): array => resolve(SignedRequests::class)->verify(Request::create('https://devnull.test/api/v1/me')))
        ->toThrow(InvalidArgumentException::class, 'Malformed SSH signature.');
});

it('rejects an expired signature', function (): void {
    $uri = 'https://devnull.test/api/v1/me';

    $request = Request::create($uri, server: signApiRequest($this->keyPair, 'GET', $uri, timestamp: now()->getTimestamp() - 301));

    expect(fn (): array => resolve(SignedRequests::class)->verify($request))
        ->toThrow(InvalidArgumentException::class, 'Signature expired. Check that your clock is right.');
});

it('rejects a replayed request', function (): void {
    $uri = 'https://devnull.test/api/v1/me';
    $server = signApiRequest($this->keyPair, 'GET', $uri);

    resolve(SignedRequests::class)->verify(Request::create($uri, server: $server));

    expect(fn (): array => resolve(SignedRequests::class)->verify(Request::create($uri, server: $server)))
        ->toThrow(InvalidArgumentException::class, 'This signature was already used.');
});
