<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\GitHub;
use App\Services\PullRequestTitles;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * @phpstan-type GitHubPullRequest array{number: int, title: string, html_url: string, merged_at: string|null, updated_at: string, merge_commit_sha: string, user: array{id: int, login: string, avatar_url: string}}
 * @phpstan-type ChangelogPullRequest array{number: int, type: string|null, breaking: bool, title: string, url: string, sha: string, commit_url: string, merged_at: string, author: array{login: string, avatar: string, username: string|null, mention: string|null, lane: int|string|null}}
 */
final readonly class ChangelogQuery
{
    public const string Branch = 'production';

    private const int PerPage = 100;

    private const int MaxPages = 10;

    private const int Days = 3;

    public function __construct(private GitHub $github, private PullRequestTitles $titles)
    {
        //
    }

    /**
     * The pull requests merged into production on the three latest days with merges before the given moment, in the
     * reader's time zone, newest merge first, and where to carry on from for the three days before them.
     *
     * GitHub can't list pull requests by merge date, only by when they were last updated, and a pull request is
     * always updated when it merges. So once a page reaches pull requests last updated before a day began, every
     * merge of that day has been seen. Pages are read until that is true of the third day, up to ten pages a time.
     *
     * @return array{pullRequests: list<ChangelogPullRequest>, olderBefore: string|null}|null
     */
    public function get(string $timeZone, ?CarbonImmutable $before = null): ?array
    {
        /** @var array<int, GitHubPullRequest> $merged */
        $merged = [];

        for ($page = 1; $page <= self::MaxPages; $page++) {
            $pullRequests = $this->page($page);

            if ($pullRequests === null) {
                return null;
            }

            foreach ($pullRequests as $pullRequest) {
                if ($pullRequest['merged_at'] !== null && (! $before instanceof CarbonImmutable || CarbonImmutable::parse($pullRequest['merged_at'])->lessThan($before))) {
                    $merged[$pullRequest['number']] = $pullRequest;
                }
            }

            $days = $this->days($merged, $timeZone);
            $isLastPage = count($pullRequests) < self::PerPage;

            if (count($days) >= self::Days) {
                $start = CarbonImmutable::parse($days[self::Days - 1], $timeZone)->startOfDay();
                $oldestUpdate = $pullRequests === [] ? null : CarbonImmutable::parse($pullRequests[count($pullRequests) - 1]['updated_at']);

                if ($isLastPage || $oldestUpdate?->lessThan($start)) {
                    $hasOlder = ! $isLastPage || count($days) > self::Days;

                    return $this->result(
                        array_filter($merged, fn (array $pullRequest): bool => ! CarbonImmutable::parse((string) $pullRequest['merged_at'])->lessThan($start)),
                        $hasOlder ? $start : null,
                    );
                }
            } elseif ($isLastPage) {
                return $this->result($merged, null);
            }
        }

        $oldest = min(array_map(fn (array $pullRequest): string => (string) $pullRequest['merged_at'], $merged) ?: [null]);

        return $this->result($merged, $oldest === null ? null : CarbonImmutable::parse($oldest));
    }

    /**
     * One page of the closed pull requests, most recently updated first. Each page is cached for half an hour per
     * asset version, and a failed read is not cached, so the next request tries again.
     *
     * @return list<GitHubPullRequest>|null
     */
    private function page(int $page): ?array
    {
        $repository = config()->string('services.github.repository');
        $version = Inertia::getVersion();

        /** @var list<GitHubPullRequest>|null */
        return Cache::remember("changelog:{$repository}:{$version}:page:{$page}", 1800, function () use ($repository, $page): ?array {
            try {
                $pullRequests = $this->github->request()->get("repos/{$repository}/pulls", [
                    'state' => 'closed',
                    'base' => self::Branch,
                    'sort' => 'updated',
                    'direction' => 'desc',
                    'per_page' => self::PerPage,
                    'page' => $page,
                ])->throw()->json();
            } catch (HttpClientException) {
                return null;
            }

            return is_array($pullRequests) && array_is_list($pullRequests) ? $pullRequests : null;
        });
    }

    /**
     * The days with merges in the reader's time zone, latest first.
     *
     * @param  array<int, GitHubPullRequest>  $merged
     * @return list<string>
     */
    private function days(array $merged, string $timeZone): array
    {
        $days = array_unique(array_map(
            fn (array $pullRequest): string => CarbonImmutable::parse((string) $pullRequest['merged_at'])->setTimezone($timeZone)->toDateString(),
            array_values($merged),
        ));

        rsort($days);

        return $days;
    }

    /**
     * Authors who are on /dev/null keep the lane they chose there, and link to their profile. They are found by their
     * GitHub account, since a username can change on either side.
     *
     * @param  array<int, GitHubPullRequest>  $merged
     * @return array{pullRequests: list<ChangelogPullRequest>, olderBefore: string|null}
     */
    private function result(array $merged, ?CarbonImmutable $olderBefore): array
    {
        usort($merged, fn (array $a, array $b): int => strcmp((string) $b['merged_at'], (string) $a['merged_at']));

        $users = User::query()
            ->whereIn('github_id', array_unique(array_map(fn (array $pullRequest): string => (string) $pullRequest['user']['id'], $merged)))
            ->get()
            ->keyBy('github_id');

        return [
            'pullRequests' => array_map(fn (array $pullRequest): array => [
                'number' => $pullRequest['number'],
                ...$this->titles->parse($pullRequest['title']),
                'url' => $pullRequest['html_url'],
                'sha' => $pullRequest['merge_commit_sha'],
                'commit_url' => 'https://github.com/'.config()->string('services.github.repository').'/commit/'.$pullRequest['merge_commit_sha'],
                'merged_at' => (string) $pullRequest['merged_at'],
                'author' => $this->author($pullRequest['user'], $users->get((string) $pullRequest['user']['id'])),
            ], $merged),
            'olderBefore' => $olderBefore?->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array{id: int, login: string, avatar_url: string}  $author
     * @return array{login: string, avatar: string, username: string|null, mention: string|null, lane: int|string|null}
     */
    private function author(array $author, ?User $user): array
    {
        return [
            'login' => $author['login'],
            'avatar' => $author['avatar_url'],
            'username' => $user?->username,
            'mention' => $user?->mention(),
            'lane' => $user?->lane(),
        ];
    }
}
