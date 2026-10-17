<?php

declare(strict_types=1);

namespace App\Services;

final readonly class SitemapXml
{
    /**
     * Render a cacheable XML document without compiling a Blade loop per URL.
     *
     * @param iterable<array{loc: string, lastmod: string, images?: list<string>}> $urls
     */
    public function render(iterable $urls): string
    {
        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        foreach ($urls as $url) {
            $body .= "    <url>\n";
            $body .= '        <loc>'.self::escape($url['loc'])."</loc>\n";
            $body .= '        <lastmod>'.self::escape($url['lastmod'])."</lastmod>\n";

            foreach ($url['images'] ?? [] as $image) {
                $body .= "        <image:image>\n";
                $body .= '            <image:loc>'.self::escape($image)."</image:loc>\n";
                $body .= "        </image:image>\n";
            }

            $body .= "    </url>\n";
        }

        return $body."</urlset>\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
    }
}
