<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\GitHub;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final readonly class UserShiptalkerIndexQuery
{
    public function __construct(private GitHub $github)
    {
        //
    }

    /**
     * Contributions are only on GitHub's GraphQL API, which needs a token.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.github.token'));
    }

    /**
     * How much the user ships on GitHub against how much they talk here, both counted since they joined, so years of
     * GitHub history don't drown out a new account. GitHub counts at most a year at a time, so the window is capped to
     * the last year. Only approved commits count, so every viewer sees the same index. The bots have no GitHub account,
     * so they ship nothing: all talk.
     *
     * @return array{shipped: int, talked: int, since: string}|null
     */
    public function get(User $user): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $since = $user->created_at->max(now()->subYear());
        $shipped = $user->hasGitHubAccount() ? $this->contributions($user, $since) : 0;

        if ($shipped === null) {
            return null;
        }

        return [
            'shipped' => $shipped,
            'talked' => $user->commits()->approved()->where('created_at', '>=', $since)->count(),
            'since' => $since->toIso8601String(),
        ];
    }

    /**
     * Cached for an hour, and a failed read is not cached, so the next view tries again.
     */
    private function contributions(User $user, CarbonInterface $since): ?int
    {
        try {
            $contributions = Cache::remember(
                "github-contributions:{$user->github_id}:{$since->toDateString()}",
                3600,
                fn (): ?int => $this->github->contributions($user->github_id, $user->username, $since),
            );
        } catch (HttpClientException $httpClientException) {
            Log::warning('Could not read the GitHub contributions.', [
                'username' => $user->username,
                'error' => $httpClientException->getMessage(),
            ]);

            return null;
        }

        return is_int($contributions) ? $contributions : null;
    }
}
