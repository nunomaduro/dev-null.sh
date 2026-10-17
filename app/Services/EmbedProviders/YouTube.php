<?php

declare(strict_types=1);

namespace App\Services\EmbedProviders;

use App\Contracts\Services\EmbedProvider;
use App\Dto\Embed;
use Illuminate\Container\Attributes\Config;
use Uri\WhatWg\Url;

final readonly class YouTube implements EmbedProvider
{
    private const array HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];

    private const string VIDEO_ID = '[A-Za-z0-9_-]{11}';

    public function __construct(#[Config('embeds.youtube.privacy_enhanced')] private bool $privacyEnhanced)
    {
        //
    }

    public function embed(Url $url): ?Embed
    {
        $host = $url->getAsciiHost();
        $path = mb_rtrim($url->getPath(), '/');
        parse_str($url->getQuery() ?? '', $query);

        if ($host === 'youtu.be' && preg_match('#^/('.self::VIDEO_ID.')$#', $path, $matches) === 1) {
            return $this->video($matches[1], $query);
        }

        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        if ($path === '/watch' && is_string($query['v'] ?? null) && preg_match('#^'.self::VIDEO_ID.'$#', $query['v']) === 1) {
            return $this->video($query['v'], $query);
        }

        if ($path === '/playlist' && is_string($query['list'] ?? null) && preg_match('#^[A-Za-z0-9_-]+$#', $query['list']) === 1) {
            return Embed::video('YouTube', $this->base().'/videoseries', ['list' => $query['list'], 'autoplay' => 1]);
        }

        if (preg_match('#^/(embed|live|shorts)/('.self::VIDEO_ID.')$#', $path, $matches) === 1) {
            return $this->video($matches[2], $query, $matches[1] === 'shorts' ? '9 / 16' : '16 / 9');
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private function video(string $id, array $query, string $aspectRatio = '16 / 9'): Embed
    {
        $start = $this->seconds($query['t'] ?? $query['start'] ?? null);

        return Embed::video('YouTube', "{$this->base()}/{$id}", ['autoplay' => 1, ...($start > 0 ? ['start' => $start] : [])], $aspectRatio, $this->poster($id));
    }

    /**
     * YouTube often answers a server's request for the page without its og:image, but every video has this thumbnail.
     */
    private function poster(string $id): string
    {
        return "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
    }

    private function base(): string
    {
        return $this->privacyEnhanced ? 'https://www.youtube-nocookie.com/embed' : 'https://www.youtube.com/embed';
    }

    private function seconds(mixed $time): int
    {
        if (! is_string($time) || preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $time, $matches) !== 1) {
            return 0;
        }

        return (int) ($matches[1] ?? 0) * 3600 + (int) ($matches[2] ?? 0) * 60 + (int) ($matches[3] ?? 0);
    }
}
