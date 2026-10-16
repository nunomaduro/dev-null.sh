<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class GitHub
{
    private const int PerPage = 100;

    public function request(): PendingRequest
    {
        $request = $this->client();

        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');

        // Authenticating as the OAuth app raises the rate limit from 60 to 5,000 requests per hour.
        if (is_string($clientId) && is_string($clientSecret)) {
            $request->withBasicAuth($clientId, $clientSecret);
        }

        return $request;
    }

    /**
     * Looks the account up by its immutable ID: a username can be renamed and then claimed by someone else.
     * Null when the account no longer exists.
     */
    public function login(string $githubId): ?string
    {
        $response = $this->request()->get("user/{$githubId}");

        if ($response->notFound()) {
            return null;
        }

        $login = $response->throw()->json('login');
        assert(is_string($login));

        return $login;
    }

    /**
     * The IDs of the accounts in a paginated list, such as the followers of a user, up to the given number of pages.
     *
     * @return list<string>
     */
    public function accountIds(string $path, int $maxPages): array
    {
        $ids = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            /** @var list<array{id: int}> $accounts */
            $accounts = $this->request()->get($path, ['per_page' => self::PerPage, 'page' => $page])->throw()->json();

            foreach ($accounts as $account) {
                $ids[] = (string) $account['id'];
            }

            if (count($accounts) < self::PerPage) {
                break;
            }
        }

        return $ids;
    }

    /**
     * The contributions since the given time, as the user's profile calendar counts them, so private ones only when the
     * user shares their private contribution count. Only GraphQL has them, and it takes a token rather than the OAuth
     * app's credentials. Null when the login now belongs to another account than the one with the given ID.
     */
    public function contributions(string $githubId, string $login, CarbonInterface $from): ?int
    {
        $response = $this->client()
            ->withToken(config()->string('services.github.token'))
            ->post('graphql', [
                'query' => 'query($login: String!, $from: DateTime!) { user(login: $login) { databaseId contributionsCollection(from: $from) { contributionCalendar { totalContributions } } } }',
                'variables' => ['login' => $login, 'from' => $from->toIso8601String()],
            ])
            ->throw();

        if ($response->json('data.user.databaseId') !== (int) $githubId) {
            return null;
        }

        $total = $response->json('data.user.contributionsCollection.contributionCalendar.totalContributions');

        return is_int($total) ? $total : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout(3)
            ->timeout(5);
    }
}
