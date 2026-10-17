<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\SitemapQuery;
use App\Services\SitemapXml;
use Carbon\Carbon;
use Illuminate\Http\Response;
use stdClass;

final readonly class CommitSitemapController
{
    public function show(int $page, SitemapQuery $sitemaps, SitemapXml $xml): Response
    {
        $commits = $sitemaps->commitsOn($page);
        $ids = $commits->map(fn (stdClass $commit): string => (string) $commit->id)->all();

        // Generate route formats once instead of resolving 10,000 named routes.
        $commitUrl = route('commit.show', ['user' => '__sitemap_user__', 'commit' => '__sitemap_commit__']);
        $imageUrl = route('commit-image.show', '__sitemap_image__');

        /** @var array<string, list<string>> $images */
        $images = [];

        foreach ($sitemaps->imagesFor($ids) as $image) {
            $images[(string) $image->commit_id][] = str_replace('__sitemap_image__', (string) $image->id, $imageUrl);
        }

        $urls = $commits->map(fn (stdClass $commit): array => [
            'loc' => str_replace(
                ['__sitemap_user__', '__sitemap_commit__'],
                [rawurlencode((string) $commit->username), (string) $commit->id],
                $commitUrl,
            ),
            'lastmod' => Carbon::parse((string) $commit->updated_at)->toAtomString(),
            'images' => $images[(string) $commit->id] ?? [],
        ]);

        // Keep a normal Response: the cache.headers middleware needs the full
        // body to compute the route's existing ETag.
        return response($xml->render($urls), headers: ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
