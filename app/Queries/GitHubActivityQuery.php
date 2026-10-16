<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\GitHub;
use App\Services\GitHubActivity;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class GitHubActivityQuery
{
    public const int CACHE_SECONDS = 3600;

    public function __construct(
        private GitHub $github,
        private GitHubActivity $githubActivity,
    ) {
        //
    }

    /**
     * The user's contribution calendar from the last year on GitHub, cached for an hour. The account is looked up by
     * its immutable ID, so a renamed username never shows someone else's year. A failed read is not cached, so the
     * next request tries again.
     *
     * @return array{login: string, days: list<array{date: string, count: int, level: int}>}|null
     */
    public function get(User $user): ?array
    {
        $key = "github-activity:{$user->github_id}";

        /** @var array{login: string, days: list<array{date: string, count: int, level: int}>}|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $login = $this->github->login($user->github_id);

            if ($login === null || preg_match('/^[A-Za-z0-9-]+$/', $login) !== 1) {
                return null;
            }

            $html = Http::baseUrl('https://github.com')
                ->accept('text/html')
                ->connectTimeout(3)
                ->timeout(5)
                ->withoutRedirecting()
                ->get("users/{$login}/contributions")
                ->throw()
                ->body();
        } catch (HttpClientException) {
            return null;
        }

        $days = $this->githubActivity->parse($html);

        if ($days === null) {
            return null;
        }

        $activity = ['login' => $login, 'days' => $days];

        Cache::put($key, $activity, self::CACHE_SECONDS);

        return $activity;
    }
}
