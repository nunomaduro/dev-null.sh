<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use JsonSerializable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error from the signed API. It answers with its message and its code, and the status that goes with the code, and
 * anything else the app needs to show it.
 */
final class ApiException extends HttpException
{
    /**
     * @param  array<mixed>  $headers
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly ApiErrorCode $errorCode, string $message, array $headers = [], public readonly array $data = [])
    {
        parent::__construct($errorCode->status(), $message, headers: $headers);
    }

    /**
     * A profile across a block is unavailable to a signed request, as on the web. Only the one who blocked the other
     * is told why, with the user they blocked as the API shows users, for the blocked page the web shows them.
     */
    public static function acrossBlock(User $user, bool $blockedByViewer, JsonSerializable $shown): self
    {
        return $blockedByViewer
            ? new self(ApiErrorCode::UserBlocked, __("You blocked :name. Neither of you can follow, reply to, or star the other, or see each other's commits.", [
                'name' => $user->name,
            ]), data: ['user' => $shown])
            : new self(ApiErrorCode::UserUnavailable, __("This profile isn't available."));
    }

    /**
     * A commit that doesn't exist, was deleted, isn't published, or that the user may not see. The web answers them
     * all alike, so nothing tells them apart.
     */
    public static function commitUnavailable(): self
    {
        return new self(ApiErrorCode::CommitUnavailable, __("This commit isn't available."));
    }

    /**
     * The one answer to a code the app can't redeem, whatever is wrong with it, as OAuth answers it.
     */
    public static function invalidGrant(): self
    {
        return new self(ApiErrorCode::InvalidGrant, __('This sign-in has expired or was already used. Sign in again in the app.'));
    }

    /**
     * The user gave every pastel they get today. The web's own words, which say when they come back.
     */
    public static function pasteisExhausted(): self
    {
        return new self(ApiErrorCode::PasteisExhausted, __('you ran out of pastéis. wait until tomorrow.'));
    }

    /**
     * The profile could not be mirrored from GitHub again, as the web says it.
     */
    public static function gitHubSyncFailed(): self
    {
        return new self(ApiErrorCode::GitHubSyncFailed, __('Could not sync from GitHub. Try again in a minute.'));
    }

    /**
     * The user is banned, so the only thing left for them is deleting their account.
     */
    public static function banned(): self
    {
        return new self(ApiErrorCode::Banned, __('Your account is banned from /dev/null. All you can do is delete it.'));
    }

    /**
     * The scheduled commit was submitted, or canceled, before it could be changed or canceled. The web's own words.
     */
    public static function scheduledCommitSubmitted(): self
    {
        return new self(ApiErrorCode::ScheduledCommitSubmitted, __('This commit was already submitted or canceled. Refresh to see its status.'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode->value,
            ...$this->data,
        ], $this->getStatusCode(), $this->getHeaders());
    }
}
