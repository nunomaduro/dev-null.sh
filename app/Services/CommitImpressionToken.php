<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final readonly class CommitImpressionToken
{
    public const int LIFETIME_MINUTES = 10;

    public const int MAX_LENGTH = 1024;

    private const string PURPOSE = 'commit-impression:v1';

    /**
     * @return array{token: string, expires_at: int}|null
     */
    public function issue(Commit $commit, ?User $viewer): ?array
    {
        if (! $viewer instanceof User || ! $commit->exists || $commit->trashed()
            || $commit->status !== CommitStatus::Approved || $commit->user_id === $viewer->id) {
            return null;
        }

        $expiresAt = now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp();
        $token = Crypt::encryptString(json_encode([
            'purpose' => self::PURPOSE,
            'viewer_id' => $viewer->id,
            'commit_id' => $commit->id,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function validFor(User $viewer, string $commitId, string $token): bool
    {
        if ($token === '' || mb_strlen($token) > self::MAX_LENGTH) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return false;
        }

        return is_array($payload)
            && ($payload['purpose'] ?? null) === self::PURPOSE
            && ($payload['viewer_id'] ?? null) === $viewer->id
            && ($payload['commit_id'] ?? null) === $commitId
            && is_int($payload['expires_at'] ?? null)
            && $payload['expires_at'] > now()->getTimestamp();
    }
}
