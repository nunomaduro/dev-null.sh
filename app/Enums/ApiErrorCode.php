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
    case DeviceNotApproved = 'device_not_approved';
    case InvalidGrant = 'invalid_grant';
    case UserBlocked = 'user_blocked';
    case UserUnavailable = 'user_unavailable';
    case OwnCommit = 'own_commit';
    case CommitUnavailable = 'commit_unavailable';
    case PasteisExhausted = 'pasteis_exhausted';
    case RateLimited = 'rate_limited';
    case GitHubSyncFailed = 'github_sync_failed';
    case TimedOut = 'timed_out';
    case Banned = 'banned';
    case CommitRulesRequired = 'commit_rules_required';
    case ScheduledCommitSubmitted = 'scheduled_commit_submitted';

    public function status(): int
    {
        return match ($this) {
            self::InvalidGrant => 400,
            self::InvalidSignature, self::DeviceNotApproved => 401,
            self::UserBlocked, self::UserUnavailable, self::OwnCommit, self::TimedOut, self::Banned, self::CommitRulesRequired => 403,
            self::CommitUnavailable => 404,
            self::ScheduledCommitSubmitted => 409,
            self::PasteisExhausted => 422,
            self::RateLimited => 429,
            self::GitHubSyncFailed => 503,
        };
    }
}
