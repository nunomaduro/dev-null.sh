<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleCommits;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Support\SessionKey;

it('limits each user to 10 commits a minute', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/commits', 'POST');
    $request->setUserResolver(fn (): User => $user);

    $middleware = new ThrottleCommits;

    for ($attempt = 0; $attempt < 10; $attempt++) {
        expect($middleware->handle($request, fn (): Response => response('Committed'))->getContent())->toBe('Committed');
    }

    $response = $middleware->handle($request, fn (): Response => response('Committed'));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and(session(SessionKey::FLASH_DATA))->toBe([
            'toast' => [
                'type' => 'error',
                'message' => 'Slow down: 10 commits a minute. Even CI takes breaks.',
            ],
        ]);
});

it('tells json requests to slow down without flashing', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3/replies', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn (): User => $user);

    RateLimiter::increment("commits:{$user->id}", 60, 10);

    $response = new ThrottleCommits()->handle($request, fn (): Response => response('Committed'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->getData(true))->toBe(['message' => 'Slow down: 10 commits a minute. Even CI takes breaks.'])
        ->and(session(SessionKey::FLASH_DATA))->toBeNull();
});

it('limits each address to 60 public api requests a minute', function (): void {
    $request = Request::create('/api/v1/users/octocat', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $limiter = RateLimiter::limiter('api');

    assert($limiter instanceof Closure);

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('203.0.113.7')
        ->and($limit->maxAttempts)->toBe(60)
        ->and($limit->decaySeconds)->toBe(60);
});

it('limits each signed-in user to 120 api requests a minute, whatever their address', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/api/v1/users/octocat', server: ['REMOTE_ADDR' => '203.0.113.7']);
    $request->setUserResolver(fn (): User => $user);

    $limiter = RateLimiter::limiter('api');

    assert($limiter instanceof Closure);

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe($user->id)
        ->and($limit->maxAttempts)->toBe(120)
        ->and($limit->decaySeconds)->toBe(60);
});

it('limits each address to 60 device redemptions a minute, before their signatures are checked', function (): void {
    $request = Request::create('/api/v1/devices', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $limiter = RateLimiter::limiter('devices');

    assert($limiter instanceof Closure);

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('203.0.113.7')
        ->and($limit->maxAttempts)->toBe(60)
        ->and($limit->decaySeconds)->toBe(60);
});

it('limits each user to 10 approvals of the devnull app a minute', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/mobile/authorize', 'POST');
    $request->setUserResolver(fn (): User => $user);

    $limiter = RateLimiter::limiter('mobile-authorizations');

    assert($limiter instanceof Closure);

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe($user->id)
        ->and($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(60);
});
