<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The code that comes with an error from the signed API, next to its message, so the mobile app can tell errors apart
 * without reading the message. Once released, a code never changes.
 */
enum ApiErrorCode: string
{
    case InvalidSignature = 'invalid_signature';
    case KeyNotOnGitHub = 'key_not_on_github';
    case RateLimited = 'rate_limited';
    case GitHubUnreachable = 'github_unreachable';
    case UserBlocked = 'user_blocked';
    case UserUnavailable = 'user_unavailable';

    public function status(): int
    {
        return match ($this) {
            self::InvalidSignature, self::KeyNotOnGitHub => 401,
            self::UserBlocked, self::UserUnavailable => 403,
            self::RateLimited => 429,
            self::GitHubUnreachable => 503,
        };
    }
}
