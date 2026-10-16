<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\SignedRequests;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request to the API signed by the mobile app with an SSH key that is on the user's GitHub account.
 *
 * The public read endpoints use it through optional(): a request without any signature header is read as a guest,
 * but one that is signed must be signed right, so a broken client never quietly gets the public view instead.
 */
final readonly class AuthenticateSignedRequest
{
    public function __construct(private SignedRequests $signedRequests)
    {
        //
    }

    public static function optional(): string
    {
        return self::class.':optional';
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($mode === 'optional' && ! $this->signedRequests->isSigned($request)) {
            return $this->asGuest($request, $next);
        }

        try {
            ['publicKey' => $publicKey, 'user' => $user] = $this->signedRequests->verify($request);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ApiException(ApiErrorCode::InvalidSignature, $invalidArgumentException->getMessage());
        }

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::KeyNotOnGitHub, __('This SSH key (:fingerprint) is not on the GitHub account of a devnull user.', [
                'fingerprint' => $publicKey->fingerprint(),
            ]));
        }

        Auth::setUser($user);

        return $next($request);
    }

    /**
     * Only a signature signs a request in. A request without one gets what a guest gets, even when a user is already
     * signed in some other way, such as on the web in a test, and that user is signed in again afterwards.
     *
     * @param  Closure(Request): (Response)  $next
     */
    private function asGuest(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $next($request);
        }

        Auth::forgetUser();

        try {
            return $next($request);
        } finally {
            Auth::setUser($user);
        }
    }
}
