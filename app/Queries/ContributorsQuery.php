<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\GitHub;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Fluent;

/**
 * @phpstan-type Account array{login: string, github_id: string, commits: int}
 * @phpstan-type Contributor array{login: string, commits: int, username: string|null}
 */
final readonly class ContributorsQuery
{
    private const int PerPage = 100;

    private const int MaxPages = 10;

    private const int Ttl = 3600;

    private const int FailedTtl = 60;

    /**
     * GitHub reports them as ordinary accounts, so they are named rather than detected.
     */
    private const array Agents = ['ampagent', 'claude'];

    public function __construct(private GitHub $github)
    {
        //
    }

    /**
     * The repository contributors, most commits first, as GitHub counts them, each with their /dev/null username, or
     * null when they aren't on it. They are found by their GitHub account, since a username can change on either side.
     *
     * @return list<Contributor>|null
     */
    public function get(): ?array
    {
        $accounts = $this->accounts();

        if ($accounts === null) {
            return null;
        }

        $usernames = User::query()
            ->whereIn('github_id', array_column($accounts, 'github_id'))
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->github_id => $user->username]);

        return array_map(fn (array $account): array => [
            'login' => $account['login'],
            'commits' => $account['commits'],
            'username' => $usernames->get($account['github_id']),
        ], $accounts);
    }

    /**
     * The GitHub accounts behind the contributors. A failed read is kept for a minute, so an outage doesn't send every
     * visitor to GitHub.
     *
     * @return list<Account>|null
     */
    private function accounts(): ?array
    {
        $repository = config()->string('services.github.repository');
        $key = "about:contributor-accounts:{$repository}";

        /** @var list<Account>|false|null $contributors */
        $contributors = Cache::get($key);

        if ($contributors === null) {
            $contributors = [];

            try {
                for ($page = 1; $page <= self::MaxPages; $page++) {
                    $accounts = $this->github->request()->get("repos/{$repository}/contributors", [
                        'per_page' => self::PerPage,
                        'page' => $page,
                    ])->throw()->collect();

                    if ($accounts->isNotEmpty() && ! array_is_list($accounts->all())) {
                        $contributors = false;

                        break;
                    }

                    $contributors = [...$contributors, ...$accounts->where('type', 'User')
                        ->mapInto(Fluent::class)
                        ->filter(fn (Fluent $account): bool => $account->string('login')->isNotEmpty()
                            && is_int($account->get('id'))
                            && is_int($account->get('contributions'))
                            && ! in_array($account->string('login')->lower()->value(), self::Agents, true))
                        ->map(fn (Fluent $account): array => [
                            'login' => $account->string('login')->value(),
                            'github_id' => (string) $account->integer('id'),
                            'commits' => $account->integer('contributions'),
                        ])
                        ->values()
                        ->all()];

                    if ($accounts->count() < self::PerPage) {
                        break;
                    }
                }
            } catch (HttpClientException) {
                $contributors = false;
            }

            Cache::put($key, $contributors, $contributors === false ? self::FailedTtl : self::Ttl);
        }

        return $contributors === false ? null : $contributors;
    }
}
