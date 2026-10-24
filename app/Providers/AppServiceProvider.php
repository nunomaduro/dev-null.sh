<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Services\DnsResolver;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\CodeTheme;
use App\Services\DnsResolvers\SystemDnsResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Symfony\Component\HttpFoundation\Response;
use Tempest\Highlight\Highlighter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(Highlighter::class, fn (): Highlighter => new Highlighter(new CodeTheme()));

        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(): void
    {
        $this->bootRateLimitingDefaults();

        $this->bootExceptionHandling();
        $this->bootPassport();
    }

    /**
     * Every visit to the consent page issues a new auth token, so the page must not reload itself (as it would for
     * deferred shared props): the form would then hold a token the session no longer knows.
     */
    private function bootPassport(): void
    {
        Passport::authorizationView(function (array $parameters): Response {
            /** @var array{client: Client, scopes: list<Scope>, authToken: string, request: Request} $parameters */
            return Inertia::render('oauth/authorize', [
                'suggestedUsers' => null,
                'client' => $parameters['client']->name,
                'scopes' => array_map(fn (Scope $scope): string => $scope->description, $parameters['scopes']),
                'authToken' => $parameters['authToken'],
                'state' => $parameters['request']->input('state'),
                'clientId' => $parameters['client']->getKey(),
                'csrfToken' => csrf_token(),
                'url' => route('passport.authorizations.approve'),
            ])->toResponse($parameters['request']);
        });
    }

    private function bootExceptionHandling(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ExceptionResponse|RedirectResponse|null {
            // API routes render their own errors (JSON, or plain text for the `devnull commit` script)...
            if ($response->request->expectsJson() || $response->request->is('api/*')) {
                return null;
            }

            if ($response->statusCode() === 419) {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => 'Page expired: your session timed out before that went through. Try again.',
                ]);

                return back();
            }

            if ($response->statusCode() === 500 && config('app.debug')) {
                return null;
            }

            if (! in_array($response->statusCode(), [403, 404, 429, 500, 503], true)) {
                return null;
            }

            return $response->render('error', [
                'status' => $response->statusCode(),
            ])->withSharedData();
        });
    }

    private function bootRateLimitingDefaults(): void
    {
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)->by($this->userId($request)));

        RateLimiter::for('commit-reports', fn (Request $request): Limit => Limit::perMinute(5)->by($this->userId($request)));

        // Every sync costs four GitHub API requests, out of a shared hourly budget. The mobile app shares it, and gets the
        // same message with a code...
        RateLimiter::for('profile-syncs', function (Request $request): Limit {
            $user = $request->user();
            assert($user instanceof User);

            return Limit::perMinute(1)->by($user->id)->response(function (Request $request, array $headers): JsonResponse|RedirectResponse {
                if ($request->is('api/*')) {
                    return new ApiException(ApiErrorCode::RateLimited, __('You just synced. Try again in a minute.'), $headers)->render();
                }

                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => 'You just synced. Try again in a minute.',
                ]);

                return back();
            });
        });

        // The mobile app's 429 comes with a code. The public API's stays as it is...
        $rateLimited = fn (Request $request, array $headers): JsonResponse => new ApiException(ApiErrorCode::RateLimited, 'Too Many Attempts.', $headers)->render();

        RateLimiter::for('api', function (Request $request) use ($rateLimited): Limit {
            $user = $request->user();

            // Signed requests from the mobile app share one budget per user, whichever network the phone is on...
            return $user instanceof User
                ? Limit::perMinute(120)->by($user->id)->response($rateLimited)
                : Limit::perMinute(60)->by((string) $request->ip());
        });

        // Redeeming a code for a device is limited per key once the signature checks out. Before that, each address gets
        // a ceiling, so unsigned or badly signed requests can't force signature checks...
        RateLimiter::for('devices', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip())->response($rateLimited));

        // Approving the devnull app on the web hands out a code each time...
        RateLimiter::for('mobile-authorizations', fn (Request $request): Limit => Limit::perMinute(10)->by($this->userId($request)));

        // GitHub fetches every README image through a few proxy addresses, so each graph gets its own budget...
        RateLimiter::for('activity-graph', fn (Request $request): Limit => Limit::perMinute(60)->by("{$request->ip()}|{$request->path()}"));

        // Commit actions: rapid j/k with a star on most commits stays well under these...
        RateLimiter::for('stars', fn (Request $request): Limit => Limit::perMinute(60)->by($this->userId($request)));
        RateLimiter::for('pins', fn (Request $request): Limit => Limit::perMinute(20)->by($this->userId($request)));

        // Messages: polling an open conversation every second or so stays well under the first...
        RateLimiter::for('conversations', fn (Request $request): Limit => Limit::perMinute(300)->by($this->userId($request)));
        RateLimiter::for('messages', fn (Request $request): Limit => Limit::perMinute(30)->by($this->userId($request)));

        // Social graph...
        RateLimiter::for('follows', fn (Request $request): Limit => Limit::perMinute(30)->by($this->userId($request)));

        // People search, mention suggestions, and hover cards share one bucket...
        RateLimiter::for('people', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->user() instanceof User ? $this->userId($request) : 'ip:'.$request->ip(),
        ));

        // Every issue lookup GitHub hasn't answered in the last five minutes costs a request out of the shared hourly budget,
        // which also keeps SSH keys in sync for the CLI, so it gets a budget of its own...
        RateLimiter::for('issue-suggestions', fn (Request $request): array => [
            Limit::perMinute(30)->by($this->userId($request)),
            Limit::perDay(300)->by('day:'.$this->userId($request)),
        ]);

        // Every commit search pays for an embedding and a relevance verdict, so it gets a budget of its own...
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(15)
            ->by($request->user() instanceof User ? $this->userId($request) : 'ip:'.$request->ip())
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => 'Slow down: too many searches. Give it a few seconds.'],
                429,
                $headers,
            )));

        // Exports stream every commit a user ever made...
        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perHour(3)->by($this->userId($request)));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user->id;
    }
}
