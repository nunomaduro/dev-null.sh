<?php

declare(strict_types=1);

namespace App\Services\LivePlatforms;

use App\Contracts\Services\LivePlatformDriver;
use App\Dto\LiveStream;
use App\Enums\LivePlatform;
use App\Services\EmbedProviders\YouTube as YouTubeEmbeds;
use App\Services\Embeds;
use Carbon\CarbonInterface;
use Uri\WhatWg\Url;

final readonly class YouTube implements LivePlatformDriver
{
    /**
     * The link is read with the platform's embed even when embeds are turned off, but only plays inline through them.
     */
    public function __construct(private YouTubeEmbeds $embeds, private Embeds $players)
    {
        //
    }

    public function accepts(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    /**
     * The link becomes its plain watch page, so a link to the embed or with a start time opens the stream itself.
     * While a video is live, its `hqdefault_live` thumbnail follows the stream rather than staying on its first frame.
     */
    public function stream(string $url, CarbonInterface $endsAt): LiveStream
    {
        $videoId = $this->videoId($url);

        $link = "https://www.youtube.com/watch?v={$videoId}";

        return new LiveStream(LivePlatform::YouTube, $link, "https://i.ytimg.com/vi/{$videoId}/hqdefault_live.jpg", $endsAt, $this->players->for($link));
    }

    /**
     * Only links that play a single video can be a stream, and only those have a poster, which carries the video's id.
     */
    private function videoId(string $url): ?string
    {
        $parsed = Url::parse($url);
        $poster = $parsed instanceof Url ? $this->embeds->embed($parsed)?->poster : null;

        return preg_match('#^https://i\.ytimg\.com/vi/([A-Za-z0-9_-]{11})/#', (string) $poster, $matches) === 1 ? $matches[1] : null;
    }
}
