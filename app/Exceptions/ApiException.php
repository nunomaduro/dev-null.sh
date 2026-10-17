<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error from the signed API. It answers with its message and its code, and the status that goes with the code.
 */
final class ApiException extends HttpException
{
    /**
     * @param  array<mixed>  $headers
     */
    public function __construct(public readonly ApiErrorCode $errorCode, string $message, array $headers = [])
    {
        parent::__construct($errorCode->status(), $message, headers: $headers);
    }

    /**
     * A profile across a block is unavailable to a signed request, as on the web. Only the one who blocked the other
     * is told why.
     */
    public static function acrossBlock(User $user, bool $blockedByViewer): self
    {
        return $blockedByViewer
            ? new self(ApiErrorCode::UserBlocked, __("You blocked :name. Neither of you can follow, reply to, or star the other, or see each other's commits.", [
                'name' => $user->name,
            ]))
            : new self(ApiErrorCode::UserUnavailable, __("This profile isn't available."));
    }

    /**
     * The one answer to a code the app can't redeem, whatever is wrong with it, as OAuth answers it.
     */
    public static function invalidGrant(): self
    {
        return new self(ApiErrorCode::InvalidGrant, __('This sign-in has expired or was already used. Sign in again in the app.'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode->value,
        ], $this->getStatusCode(), $this->getHeaders());
    }
}
