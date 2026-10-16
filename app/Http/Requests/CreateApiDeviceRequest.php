<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuthorizationCode;
use App\Services\SignedRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The devnull app redeems the code the web handed it for a device, signed with the key it asked to approve. The key
 * isn't approved yet, so only the signature itself is checked here, and the code has to be one that was handed out for
 * that key.
 */
final class CreateApiDeviceRequest extends FormRequest
{
    private const int RedemptionsPerKeyPerMinute = 12;

    private string $fingerprint = '';

    private ?AuthorizationCode $authorizationCode = null;

    /**
     * Once the signature checks out, each key may try a few times a minute. Phones behind one address would throttle
     * each other if it went by address, which the route limits before any signature is checked.
     */
    public function authorize(SignedRequests $signedRequests): bool
    {
        try {
            $this->fingerprint = $signedRequests->verifySignature($this)->fingerprint();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ApiException(ApiErrorCode::InvalidSignature, $invalidArgumentException->getMessage());
        }

        $key = "devices:{$this->fingerprint}";

        throw_if(RateLimiter::tooManyAttempts($key, self::RedemptionsPerKeyPerMinute), fn (): ApiException => new ApiException(ApiErrorCode::RateLimited, __('Too Many Attempts.'), [
            'Retry-After' => RateLimiter::availableIn($key),
        ]));

        RateLimiter::hit($key);

        return true;
    }

    /**
     * The verifier is the one the app made its PKCE challenge from (RFC 7636).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9._~-]{43,128}$/'],
        ];
    }

    public function authorizationCode(): AuthorizationCode
    {
        assert($this->authorizationCode instanceof AuthorizationCode);

        return $this->authorizationCode;
    }

    /**
     * Whatever is wrong with the code, it gets the same answer: one that is unknown, expired or used, handed out for
     * another key, or whose challenge the verifier doesn't match.
     */
    public function passedValidation(): void
    {
        $code = AuthorizationCode::query()
            ->where('code_hash', AuthorizationCode::hash($this->string('code')->value()))
            ->where('expires_at', '>', now())
            ->first();

        throw_unless(
            $code instanceof AuthorizationCode
                && hash_equals($code->fingerprint, $this->fingerprint)
                && hash_equals($code->code_challenge, AuthorizationCode::challengeFor($this->string('code_verifier')->value())),
            ApiException::invalidGrant(),
        );

        $this->authorizationCode = $code;
    }
}
