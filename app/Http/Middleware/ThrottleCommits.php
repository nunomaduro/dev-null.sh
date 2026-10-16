<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final readonly class ThrottleCommits
{
    private const int PER_MINUTE = 10;

    /**
     * One budget of commits a minute, however they are posted.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $key = "commits:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return $this->slowDown($request, RateLimiter::availableIn($key));
        }

        RateLimiter::increment($key, 60);

        return $next($request);
    }

    private function slowDown(Request $request, int $retryAfter): Response
    {
        $message = __('Slow down: 10 commits a minute. Even CI takes breaks.');

        // The `devnull commit` script prints plain text...
        if ($request->routeIs('cli-commit.store')) {
            return response($message."\n", 429, ['Retry-After' => $retryAfter])
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        // The mobile app reads JSON with a code...
        if ($request->routeIs('api-commit.store')) {
            return new ApiException(ApiErrorCode::RateLimited, $message, ['Retry-After' => $retryAfter])->render();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, ['Retry-After' => $retryAfter]);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);

        return back();
    }
}
