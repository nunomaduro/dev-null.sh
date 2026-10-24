<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Queries\UpstreamContributionsQuery;
use App\Services\GitHub;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

#[Backoff([60, 600])]
#[Timeout(120)]
#[Tries(3)]
#[UniqueFor(3600)]
final class SyncUpstreamContributions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int PerPage = 100;

    private const int MaxPages = 10;

    /**
     * Reads the contributor list page by page. A failed read throws before the map is stored, so an outage never
     * hides the badges and the retry fills the map again.
     */
    public function handle(GitHub $github): void
    {
        /** @var array<int|string, int> $contributions */
        $contributions = [];
        $repository = config()->string('services.github.repository');

        for ($page = 1; $page <= self::MaxPages; $page++) {
            $body = $github->request()
                ->get("repos/{$repository}/contributors", ['per_page' => self::PerPage, 'page' => $page])
                ->throw()
                ->json();

            throw_if(! is_array($body) || ! array_is_list($body), RuntimeException::class, 'GitHub returned an unexpected contributor list.');

            throw_if($page === 1 && $body === [], RuntimeException::class, 'GitHub returned an empty contributor list.');

            /** @var list<array{id?: int, type?: string, contributions?: int}> $contributors */
            $contributors = $body;

            foreach ($contributors as $contributor) {
                if (($contributor['type'] ?? null) !== 'User' || ! isset($contributor['id'], $contributor['contributions'])) {
                    continue;
                }

                $contributions[$contributor['id']] = $contributor['contributions'];
            }

            if (count($contributors) < self::PerPage) {
                break;
            }
        }

        Cache::put(UpstreamContributionsQuery::CacheKey, $contributions, now()->addDay());
    }
}
