<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use App\Models\Commit;
use App\Models\Device;
use App\Models\SshKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('authenticates a request signed with a key the user approved as a device', function (): void {
    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'));

    $response->assertOk()
        ->assertJsonPath('data.id', $this->user->id)
        ->assertCookieMissing(config('session.cookie'));

    $this->assertAuthenticatedAs($this->user);
});

it('accepts requests signed by ssh-keygen over the documented message', function (string $message, string $signature, int $status): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(1791000000));

    // Made with `ssh-keygen -t ed25519`, then `ssh-keygen -Y sign -n devnull-api` over each message...
    $publicKey = SshPublicKey::fromOpenSsh('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIrhcHdLGpoKRYqI6eBWDeFu3VITYyTHDGiQ7QhYXCPa vector');
    $user = User::factory()->create();

    Device::factory()->for($user)->withPublicKey($publicKey)->create();

    [$timestamp, $nonce, $method, $target, $body] = explode("\n", $message, 5);

    $response = $this->call($method, $target, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DEVNULL_TIMESTAMP' => $timestamp,
        'HTTP_X_DEVNULL_NONCE' => $nonce,
        'HTTP_X_SSH_SIGNATURE' => $signature,
    ], content: $body);

    $response->assertStatus($status);

    $this->assertAuthenticatedAs($user);
})->with([
    'with a query and no body' => [
        "1791000000\n4f3c2b1a09e8d7c6b5a4938271605f4e\nGET\n/api/v1/log/latest?cursor=abc\n",
        'U1NIU0lHAAAAAQAAADMAAAALc3NoLWVkMjU1MTkAAAAgiuFwd0samgpFiojp4FYN4W7dUhNjJMcMaJDtCFhcI9oAAAALZGV2bnVsbC1hcGkAAAAAAAAABnNoYTUxMgAAAFMAAAALc3NoLWVkMjU1MTkAAABAKZ8la0GdO1nis8Bj7T2TApm89jTsN0of83/YJILbEsR6k/1q08FOHkSl3eZRIPoRVIjJTkXvqWhKlGMe31QwBw==',
        200,
    ],
    'with the raw body, spaces and trailing newline included' => [
        "1791000000\n0123456789abcdef0123456789abcdef\nPOST\n/api/v1/commits\n{\"body\": \"It was DNS.\", \"parent_id\": null}\n",
        'U1NIU0lHAAAAAQAAADMAAAALc3NoLWVkMjU1MTkAAAAgiuFwd0samgpFiojp4FYN4W7dUhNjJMcMaJDtCFhcI9oAAAALZGV2bnVsbC1hcGkAAAAAAAAABnNoYTUxMgAAAFMAAAALc3NoLWVkMjU1MTkAAABAZrHU0K+nsKCLlC5abo8rZbyiRE0aBSBDvXD2285XW8VE/L1Zx6VBP+Hx+XltHDV9TfGNJPDW+lmrHvsIEjU8BA==',
        201,
    ],
]);

it('rejects a request that differs from the one that was signed', function (): void {
    $server = signApiRequest($this->keyPair, 'GET', route('api-current-user.show'));

    $response = $this->call('GET', route('api-current-user.show', ['tampered' => 1]), server: $server);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid signature.')
        ->assertJsonPath('code', 'invalid_signature');
});

it('rejects a signature for another namespace', function (): void {
    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'), namespace: 'devnull-commit');

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid signature.')
        ->assertJsonPath('code', 'invalid_signature');
});

it('rejects a request without a signature', function (): void {
    $response = $this->getJson(route('api-current-user.show'));

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Malformed SSH signature.')
        ->assertJsonPath('code', 'invalid_signature');
});

it('rejects an expired or future timestamp', function (int $offset): void {
    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'), timestamp: now()->getTimestamp() + $offset);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Signature expired. Check that your clock is right.')
        ->assertJsonPath('code', 'invalid_signature');
})->with([-301, 301]);

it('rejects a malformed nonce', function (): void {
    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'), nonce: 'nonce');

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Malformed payload.')
        ->assertJsonPath('code', 'invalid_signature');
});

it('rejects a reused nonce', function (): void {
    $nonce = bin2hex(random_bytes(16));

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'), nonce: $nonce)->assertOk();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'), nonce: $nonce);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'This signature was already used.')
        ->assertJsonPath('code', 'invalid_signature');
});

it('rejects a key nobody approved as a device, without asking github', function (): void {
    $keyPair = SshKeyPair::generate();
    $user = User::factory()->create();

    // Even on the user's GitHub account, a key needs approving on the web...
    SshKey::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();

    $response = signedApiRequest($keyPair, 'GET', route('api-current-user.show'));

    $response->assertUnauthorized()
        ->assertExactJson([
            'message' => "This key isn't approved for the /dev/null app. Sign in again in the app.",
            'code' => 'device_not_approved',
        ]);

    $this->assertGuest();
    Http::assertNothingSent();
});

it('rejects a key that is not approved as a device on the public endpoints too', function (Closure $uri): void {
    $keyPair = SshKeyPair::generate();
    $commit = Commit::factory()->create();

    $response = signedApiRequest($keyPair, 'GET', $uri($commit));

    $response->assertUnauthorized()
        ->assertJsonPath('code', 'device_not_approved')
        ->assertJsonMissingPath('data');

    $this->assertGuest();
})->with([
    'a profile' => fn (Commit $commit): string => route('api-user.show', $commit->user),
    'a list of commits' => fn (Commit $commit): string => route('api-user-commit.index', $commit->user),
    'a commit' => fn (Commit $commit): string => route('api-commit.show', $commit),
]);

it("signs in with an approved device whose key is on nobody's github account, without asking github", function (): void {
    expect(SshKey::query()->exists())->toBeFalse();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);

    Http::assertNothingSent();
});

it('signs in as the user who approved the device, whoever has the key on github', function (): void {
    $other = User::factory()->create();

    SshKey::factory()->for($other)->withPublicKey($this->keyPair->publicKey)->create();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);

    $this->assertAuthenticatedAs($this->user);
});

it('rejects a revoked device', function (): void {
    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))->assertOk();

    $this->user->devices()->sole()->delete();
    Auth::forgetGuards();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'));

    $response->assertUnauthorized()
        ->assertJsonPath('code', 'device_not_approved');
});

it('records when a device was last used, at most once a minute', function (): void {
    $device = $this->user->devices()->sole();

    expect($device->last_used_at)->toBeNull();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))->assertOk();

    expect($device->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    $usedAt = now();
    $this->travel(59)->seconds();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))->assertOk();

    expect($device->refresh()->last_used_at?->toDateTimeString())->toBe($usedAt->toDateTimeString());

    $this->travel(1)->seconds();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))->assertOk();

    expect($device->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('never answers a badly signed request to a public endpoint with the public view', function (Closure $uri, Closure $server, string $message): void {
    $uri = $uri(Commit::factory()->create());

    $response = $this->call('GET', $uri, server: $server($this->keyPair, $uri));

    $response->assertUnauthorized()
        ->assertJsonPath('message', $message)
        ->assertJsonPath('code', 'invalid_signature')
        ->assertJsonMissingPath('data');

    $this->assertGuest();
})->with([
    'a profile' => fn (Commit $commit): string => route('api-user.show', $commit->user),
    'a list of commits' => fn (Commit $commit): string => route('api-user-commit.index', $commit->user),
    'a commit' => fn (Commit $commit): string => route('api-commit.show', $commit),
    'a commit that does not exist' => fn (): string => 'api/v1/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3',
])->with([
    'a wrong signature' => [
        fn (SshKeyPair $keyPair, string $uri): array => [...signApiRequest($keyPair, 'GET', $uri), 'HTTP_X_DEVNULL_NONCE' => str_repeat('0', 32)],
        'Invalid signature.',
    ],
    'an empty signature' => [
        fn (SshKeyPair $keyPair, string $uri): array => [...signApiRequest($keyPair, 'GET', $uri), 'HTTP_X_SSH_SIGNATURE' => ''],
        'Malformed SSH signature.',
    ],
    'an expired signature' => [
        fn (SshKeyPair $keyPair, string $uri): array => signApiRequest($keyPair, 'GET', $uri, timestamp: now()->getTimestamp() - 301),
        'Signature expired. Check that your clock is right.',
    ],
]);

it('answers a request without a signature with the public view, whoever is signed in on the web', function (Closure $uri): void {
    $commit = Commit::factory()->for($this->user)->pending()->create();
    $public = Commit::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->getJson($uri($commit))
        ->assertJsonMissingPath('data.status')
        ->assertJsonMissing(['id' => $commit->id]);

    $this->assertAuthenticatedAs($this->user);
    $this->getJson(route('api-commit.show', $public))->assertOk()->assertJsonMissingPath('data.status');
})->with([
    'a profile' => fn (Commit $commit): string => route('api-user.show', $commit->user),
    'a list of commits' => fn (Commit $commit): string => route('api-user-commit.index', $commit->user),
    'a commit' => fn (Commit $commit): string => route('api-commit.show', $commit),
]);

it('treats any one of the signature headers as a signed request', function (string $header): void {
    $commit = Commit::factory()->create();

    $response = $this->getJson(route('api-commit.show', $commit), [$header => '1']);

    $response->assertUnauthorized();
})->with(['X-Devnull-Timestamp', 'X-Devnull-Nonce', 'X-SSH-Signature']);

it('limits each signed-in user to 120 requests a minute, wherever they come from', function (): void {
    $sign = fn (string $address): array => [...signApiRequest($this->keyPair, 'GET', route('api-current-user.show')), 'REMOTE_ADDR' => $address];

    foreach (range(1, 120) as $attempt) {
        $this->call('GET', route('api-current-user.show'), server: $sign('10.0.0.1'))->assertOk();
    }

    $this->call('GET', route('api-current-user.show'), server: $sign('10.0.0.1'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertJsonPath('message', 'Too Many Attempts.')
        ->assertJsonPath('code', 'rate_limited');

    $this->call('GET', route('api-current-user.show'), server: $sign('10.0.0.2'))->assertTooManyRequests();

    $this->call('GET', route('api-current-user.show'), server: [...signApiRequest(apiKeyPairFor(User::factory()->create()), 'GET', route('api-current-user.show')), 'REMOTE_ADDR' => '10.0.0.1'])
        ->assertOk();

    Auth::forgetGuards();

    $this->getJson(route('api-user.show', $this->user), ['REMOTE_ADDR' => '10.0.0.1'])
        ->assertOk()
        ->assertJsonMissingPath('data.lane');
});

it("leaves the public api's 429 without a code", function (): void {
    $commit = Commit::factory()->create();

    foreach (range(1, 60) as $attempt) {
        $this->getJson(route('api-commit.show', $commit))->assertOk();
    }

    $this->getJson(route('api-commit.show', $commit))
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too Many Attempts.')
        ->assertJsonMissingPath('code');
});

it('answers unknown api routes in json', function (): void {
    $response = $this->get('api/v1/unknown');

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});
