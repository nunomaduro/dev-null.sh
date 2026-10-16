<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Services\DnsResolver;
use App\Models\User;
use App\Queries\DirectMessagingQuery;
use App\Services\DnsResolvers\SystemDnsResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        $this->bootRateLimitingDefaults();

        RateLimiter::for('direct-messages', function (Request $request): array {
            $user = $request->user();
            $recipient = $request->route('user');
            assert($user instanceof User);
            $recipient = $recipient instanceof User ? $recipient : User::query()->where('username', $recipient)->first();
            $limits = [Limit::perMinute(30)->by('dm:'.$user->id)];
            if ($recipient instanceof User && resolve(DirectMessagingQuery::class)->between($user, $recipient) === null && ! $recipient->isFollowing($user)) {
                $limits[] = Limit::perDay(10)->by('dm-requests:'.$user->id);
            }

            return $limits;
        });

        $this->bootExceptionHandling();
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
                    'message' => __('Page expired: your session timed out before that went through. Try again.'),
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
        RateLimiter::for('commit-reports', fn (Request $request): Limit => Limit::perMinute(5)->by($this->userId($request)));

        // Every sync costs four GitHub API requests, out of a shared hourly budget...
        RateLimiter::for('profile-syncs', function (Request $request): Limit {
            $user = $request->user();
            assert($user instanceof User);

            return Limit::perMinute(1)->by($user->id)->response(function (): RedirectResponse {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => __('You just synced. Try again in a minute.'),
                ]);

                return back();
            });
        });

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        // GitHub fetches every README image through a few proxy addresses, so each graph gets its own budget...
        RateLimiter::for('activity-graph', fn (Request $request): Limit => Limit::perMinute(60)->by("{$request->ip()}|{$request->path()}"));

        // Commit actions: rapid j/k with a star or bookmark on most commits stays well under these...
        RateLimiter::for('stars', fn (Request $request): Limit => Limit::perMinute(60)->by($this->userId($request)));
        RateLimiter::for('bookmarks', fn (Request $request): Limit => Limit::perMinute(60)->by($this->userId($request)));
        RateLimiter::for('pins', fn (Request $request): Limit => Limit::perMinute(20)->by($this->userId($request)));

        // Social graph...
        RateLimiter::for('follows', fn (Request $request): Limit => Limit::perMinute(30)->by($this->userId($request)));

        // People search, mention suggestions, and hover cards share one bucket...
        RateLimiter::for('people', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->user() instanceof User ? $this->userId($request) : 'ip:'.$request->ip(),
        ));

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
