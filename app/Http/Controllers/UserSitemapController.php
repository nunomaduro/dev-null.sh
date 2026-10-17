<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\SitemapQuery;
use Illuminate\Http\Response;

final readonly class UserSitemapController
{
    public function show(int $page, SitemapQuery $sitemaps): Response
    {
        return response()->view('sitemaps.urls', [
            'urls' => array_map(fn (array $user): array => [
                'loc' => route('user.show', $user['username']),
                'lastmod' => $user['updated_at']->toAtomString(),
            ], $sitemaps->usersOn($page)),
        ], headers: ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
