<?php

declare(strict_types=1);

use App\Dto\AuthorizationCode;
use App\Models\User;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GitHubUser;
use Tests\Support\SshKeyPair;

beforeEach(function (): void {
    $this->keyPair = SshKeyPair::generate();
    $this->parameters = [
        'fingerprint' => $this->keyPair->publicKey->fingerprint(),
        'device_name' => 'devnull on iPhone',
        'code_challenge' => AuthorizationCode::challengeFor(str_repeat('v', 43)),
        'code_challenge_method' => 'S256',
        'state' => 'af0ifjsldkj',
        'redirect_uri' => 'devnull://auth/callback',
    ];

    $this->codesHandedOut = 0;

    Event::listen(function (KeyWritten $event): void {
        if (str_starts_with($event->key, 'authorization-code:')) {
            $this->codesHandedOut++;
        }
    });
});

it('asks the signed-in user to approve the app on the device', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('mobile-authorization.show', $this->parameters));

    $response->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('mobile-authorization/show')
            ->where('authorization', $this->parameters)
            ->where('cancelUrl', 'devnull://auth/callback?error=access_denied&state=af0ifjsldkj')
            ->where('csrfToken', session()->token())
            ->where('auth.user.id', $user->id));
});

it('sends a guest through the github sign-in, then back to approve the app', function (): void {
    Queue::fake();
    fakeGitHubSponsorsListing();

    $this->get(route('mobile-authorization.show', $this->parameters))->assertRedirectToRoute('login');

    $user = User::factory()->create(['github_id' => '583231']);

    Socialite::fake('github', GitHubUser::fake([
        'id' => '583231',
        'nickname' => $user->username,
    ]));

    $location = (string) $this->get(route('login.store'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(strtok($location, '?'))->toBe(route('mobile-authorization.show'))
        ->and(collect($query)->sortKeys()->all())->toBe(collect($this->parameters)->sortKeys()->all());

    $this->assertAuthenticatedAs($user);
});

it('answers a request the app did not make right with a plain error page, and never a redirect', function (array $parameters, string $error): void {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('mobile-authorization.show', [...$this->parameters, ...$parameters]));

    $response->assertBadRequest()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('mobile-authorization/show')
            ->where('error', $error)
            ->missing('authorization')
            ->missing('cancelUrl'));
})->with([
    'no fingerprint' => [['fingerprint' => null], 'The fingerprint field is required.'],
    'a malformed fingerprint' => [['fingerprint' => 'MD5:aa:bb'], 'The fingerprint field format is invalid.'],
    'no device name' => [['device_name' => null], 'The device name field is required.'],
    'a long device name' => [['device_name' => str_repeat('a', 101)], 'The device name field must not be greater than 100 characters.'],
    'no challenge' => [['code_challenge' => null], 'The code challenge field is required.'],
    'a malformed challenge' => [['code_challenge' => 'short'], 'The code challenge field format is invalid.'],
    'a plain challenge' => [['code_challenge_method' => 'plain'], 'The selected code challenge method is invalid.'],
    'no state' => [['state' => null], 'The state field is required.'],
    'no redirect' => [['redirect_uri' => null], 'The redirect uri field is required.'],
    'a redirect to a website' => [['redirect_uri' => 'https://evil.example/callback'], 'The redirect uri field must start with one of the following: devnull://.'],
    'a redirect to another app' => [['redirect_uri' => 'otherapp://auth/callback'], 'The redirect uri field must start with one of the following: devnull://.'],
    'a redirect with a fragment' => [['redirect_uri' => 'devnull://auth/callback#x'], 'The redirect uri field format is invalid.'],
]);

it('sends the app back with a one-time code and its state once the user approves it', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('mobile-authorization.store'), $this->parameters);

    $response->assertStatus(303);

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('devnull://auth/callback?code=')
        ->and($query['state'])->toBe('af0ifjsldkj')
        ->and($query['code'])->toBeString();

    expect(Cache::get(AuthorizationCode::keyFor($query['code'])))->toBe([
        'user_id' => $user->id,
        'fingerprint' => $this->keyPair->publicKey->fingerprint(),
        'device_name' => 'devnull on iPhone',
        'code_challenge' => $this->parameters['code_challenge'],
    ]);
});

it('keeps the query a redirect already has', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('mobile-authorization.store'), [...$this->parameters, 'redirect_uri' => 'devnull://auth/callback?app=1']);

    expect((string) $response->headers->get('Location'))->toStartWith('devnull://auth/callback?app=1&code=')
        ->toEndWith('&state=af0ifjsldkj');
});

it('approves nothing the app did not ask for right', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('mobile-authorization.store'), [...$this->parameters, 'redirect_uri' => 'https://evil.example/callback']);

    $response->assertBadRequest()
        ->assertInertia(fn (Assert $page): Assert => $page->where('error', 'The redirect uri field must start with one of the following: devnull://.'));

    expect($this->codesHandedOut)->toBe(0);
});

it('approves only for a signed-in user, with a csrf token', function (): void {
    $this->post(route('mobile-authorization.store'), $this->parameters)->assertRedirectToRoute('home');

    expect($this->codesHandedOut)->toBe(0);
});

it('lets each user approve the app 10 times a minute', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)->post(route('mobile-authorization.store'), $this->parameters)->assertStatus(303);
    }

    $this->actingAs($user)->post(route('mobile-authorization.store'), $this->parameters)->assertTooManyRequests();

    expect($this->codesHandedOut)->toBe(10);
});
