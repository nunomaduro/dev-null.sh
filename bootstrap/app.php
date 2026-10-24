<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Enums\Log;
use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateSignedRequest;
use App\Http\Middleware\AuthenticateSshSignature;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventFraming;
use App\Http\Middleware\RedirectBannedUsers;
use App\Http\Middleware\StripNullBytes;
use App\Http\Middleware\UpdateLastSeenAt;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before the strings are trimmed, so a value that was only NUL bytes ends up empty like any other blank one.
        $middleware->prepend(StripNullBytes::class);
        $middleware->append(PreventFraming::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'who_to_follow', 'who_to_follow_round', 'lane_dimming', 'replies_per_page', 'auto_picture_in_picture']);

        $middleware->redirectGuestsTo(fn (): string => route('home'));
        $middleware->redirectUsersTo(fn (Request $request): string => route('log.show', $request->user()->log ?? Log::Following));

        // Rate limits apply to the user authenticated by their SSH signature...
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateSshSignature::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateSignedRequest::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            UpdateLastSeenAt::class,
            RedirectBannedUsers::class,
        ]);

        $middleware->api(append: [
            UpdateLastSeenAt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // The `devnull commit` script prints error responses as they are, so they are always plain text...
        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            if (! $request->is('api/cli/*') || $exception instanceof HttpResponseException) {
                return null;
            }

            [$status, $message] = match (true) {
                $exception instanceof ValidationException => [422, $exception->validator->errors()->first()],
                $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), $exception->getMessage()],
                $exception instanceof ConnectionException, $exception instanceof RequestException => [503, 'Could not reach GitHub to check your SSH key. Try again in a moment.'],
                default => [500, ''],
            };

            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response(($message ?: Response::$statusTexts[$status] ?? 'Error.')."\n", $status, $headers)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        });

        // The limits the web puts on social actions apply to the mobile app too, and answer it with a code...
        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request): ?JsonResponse => $request->is('api/v1/*') && $request->user() instanceof User
            ? new ApiException(ApiErrorCode::RateLimited, $exception->getMessage(), $exception->getHeaders())->render()
            : null);
    })->create();
