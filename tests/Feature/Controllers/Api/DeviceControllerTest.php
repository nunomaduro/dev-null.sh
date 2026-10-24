<?php

declare(strict_types=1);

use App\Actions\CreateAuthorizationCode;
use App\Actions\CreateDevice;
use App\Dto\AuthorizationCode;
use App\Dto\MobileAuthorization;
use App\Models\Device;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->user = User::factory()->create(['username' => 'octocat']);
    $this->keyPair = SshKeyPair::generate();
    $this->verifier = str_repeat('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 2);

    $this->code = resolve(CreateAuthorizationCode::class)->handle($this->user, new MobileAuthorization(
        $this->keyPair->publicKey->fingerprint(), 'devnull on iPhone', AuthorizationCode::challengeFor($this->verifier), 'state', 'devnull://auth/callback',
    ));
});

it('approves the key that redeems the code as a device, and answers like me', function (): void {
    $response = signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier]);

    $response->assertOk()
        ->assertJsonPath('data.id', $this->user->id)
        ->assertJsonPath('data.username', 'octocat')
        ->assertJsonPath('data.lane', $this->user->lane())
        ->assertJsonPath('data.automatic_lane', $this->user->automaticLane())
        ->assertJsonPath('data.commits_count', 0)
        ->assertJsonPath('data.pasteis_mode', false)
        ->assertJsonPath('unread_notifications_count', 0);

    $device = Device::query()->sole();

    expect($device->user_id)->toBe($this->user->id)
        ->and($device->fingerprint)->toBe($this->keyPair->publicKey->fingerprint())
        ->and($device->name)->toBe('devnull on iPhone')
        ->and($device->approved_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(Cache::has(AuthorizationCode::keyFor($this->code)))->toBeFalse();

    signedApiRequest($this->keyPair, 'GET', route('api-current-user.show'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);

    Http::assertNothingSent();
});

it('never needs the key to be on github', function (): void {
    expect(SshKey::query()->exists())->toBeFalse();

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertOk();
});

it('refuses a code it cannot redeem, the same way whatever is wrong with it', function (Closure $request): void {
    $response = $request($this);

    $response->assertBadRequest()
        ->assertExactJson([
            'message' => 'This sign-in has expired or was already used. Sign in again in the app.',
            'code' => 'invalid_grant',
        ]);

    expect(Device::query()->exists())->toBeFalse();
})->with([
    'a wrong verifier' => fn ($test): TestResponse => signedApiRequest($test->keyPair, 'POST', route('api-device.store'), ['code' => $test->code, 'code_verifier' => str_repeat('a', 43)]),
    'an unknown code' => fn ($test): TestResponse => signedApiRequest($test->keyPair, 'POST', route('api-device.store'), ['code' => 'another-code', 'code_verifier' => $test->verifier]),
    'another key' => fn ($test): TestResponse => signedApiRequest(SshKeyPair::generate(), 'POST', route('api-device.store'), ['code' => $test->code, 'code_verifier' => $test->verifier]),
    'an expired code' => function ($test): TestResponse {
        $test->travel(AuthorizationCode::EXPIRES_AFTER_MINUTES)->minutes();

        return signedApiRequest($test->keyPair, 'POST', route('api-device.store'), ['code' => $test->code, 'code_verifier' => $test->verifier]);
    },
    'a code whose user was deleted' => function ($test): TestResponse {
        $test->user->delete();

        return signedApiRequest($test->keyPair, 'POST', route('api-device.store'), ['code' => $test->code, 'code_verifier' => $test->verifier]);
    },
]);

it('refuses to redeem a code for a banned user', function (): void {
    $this->user->update(['banned_at' => now()]);

    $response = signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier]);

    $response->assertForbidden()
        ->assertExactJson([
            'message' => 'Your account is banned from /dev/null. All you can do is delete it.',
            'code' => 'banned',
        ]);

    expect(Device::query()->exists())->toBeFalse();
});

it('refuses a code handed out for another key, and leaves it for that key', function (): void {
    $other = SshKeyPair::generate();

    $code = resolve(CreateAuthorizationCode::class)->handle(User::factory()->create(), new MobileAuthorization(
        $other->publicKey->fingerprint(), 'devnull on Pixel', AuthorizationCode::challengeFor($this->verifier), 'state', 'devnull://auth/callback',
    ));

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $code, 'code_verifier' => $this->verifier])
        ->assertBadRequest()
        ->assertJsonPath('code', 'invalid_grant');

    signedApiRequest($other, 'POST', route('api-device.store'), ['code' => $code, 'code_verifier' => $this->verifier])
        ->assertOk();
});

it('redeems a code only once', function (): void {
    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])->assertOk();

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertBadRequest()
        ->assertJsonPath('code', 'invalid_grant');
});

it('approves again a key approved for someone else, for the user who approved it now', function (): void {
    $previous = Device::factory()->withPublicKey($this->keyPair->publicKey)->create();

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);

    expect($previous->refresh()->user_id)->toBe($this->user->id);
});

it('refuses a code that was redeemed by another request in the meantime', function (): void {
    // The code is checked, then redeemed by another request before this one approves its device...
    Event::listen(function (CacheHit $event): void {
        if ($event->key === AuthorizationCode::keyFor($this->code) && is_array($event->value)) {
            resolve(CreateDevice::class)->handle(AuthorizationCode::fromArray($event->key, $event->value));
        }
    });

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertBadRequest()
        ->assertJsonPath('code', 'invalid_grant');

    expect(Device::query()->count())->toBe(1);
});

it('validates the code and the verifier', function (array $data, string $field): void {
    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no code' => [['code_verifier' => str_repeat('a', 43)], 'code'],
    'no verifier' => [['code' => 'the-code'], 'code_verifier'],
    'a short verifier' => [['code' => 'the-code', 'code_verifier' => str_repeat('a', 42)], 'code_verifier'],
    'a long verifier' => [['code' => 'the-code', 'code_verifier' => str_repeat('a', 129)], 'code_verifier'],
    'a verifier with other characters' => [['code' => 'the-code', 'code_verifier' => str_repeat('a', 42).'!'], 'code_verifier'],
]);

it('rejects a request that is not signed right', function (Closure $server, string $message): void {
    $body = json_encode(['code' => 'the-code', 'code_verifier' => $this->verifier], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', route('api-device.store'), server: $server($this->keyPair, $body), content: $body);

    $response->assertUnauthorized()
        ->assertExactJson(['message' => $message, 'code' => 'invalid_signature']);

    expect(Device::query()->exists())->toBeFalse();
})->with([
    'no signature' => [fn (): array => ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], 'Malformed SSH signature.'],
    'a signature over another body' => [fn (SshKeyPair $keyPair): array => signApiRequest($keyPair, 'POST', route('api-device.store'), '{}'), 'Invalid signature.'],
    'an expired signature' => [fn (SshKeyPair $keyPair, string $body): array => signApiRequest($keyPair, 'POST', route('api-device.store'), $body, timestamp: now()->getTimestamp() - 301), 'Signature expired. Check that your clock is right.'],
]);

it('limits each key to 12 tries a minute, wherever they come from', function (): void {
    foreach (range(1, 12) as $attempt) {
        $server = [...signApiRequest($this->keyPair, 'POST', route('api-device.store'), '{"code":"wrong","code_verifier":"'.str_repeat('a', 43).'"}'), 'REMOTE_ADDR' => "203.0.113.{$attempt}"];

        $this->call('POST', route('api-device.store'), server: $server, content: '{"code":"wrong","code_verifier":"'.str_repeat('a', 43).'"}')->assertBadRequest();
    }

    signedApiRequest($this->keyPair, 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60)
        ->assertExactJson(['message' => 'Too Many Attempts.', 'code' => 'rate_limited']);

    signedApiRequest(SshKeyPair::generate(), 'POST', route('api-device.store'), ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertBadRequest();
});

it('limits each address to 60 tries a minute, before checking signatures', function (): void {
    foreach (range(1, 60) as $attempt) {
        $this->postJson(route('api-device.store'))->assertUnauthorized();
    }

    $this->postJson(route('api-device.store'))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limited');
});
