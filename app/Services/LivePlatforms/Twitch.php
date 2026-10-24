<?php

declare(strict_types=1);

namespace App\Services\LivePlatforms;

use App\Contracts\Services\LivePlatformDriver;
use App\Dto\LiveStream;
use App\Enums\LivePlatform;
use App\Services\EmbedProviders\Twitch as TwitchEmbeds;
use App\Services\Embeds;
use Carbon\CarbonInterface;
use Uri\WhatWg\Url;

final readonly class Twitch implements LivePlatformDriver
{
    /**
     * The link is read with the platform's embed even when embeds are turned off, but only plays inline through them.
     */
    public function __construct(private TwitchEmbeds $embeds, private Embeds $players)
    {
        //
    }

    public function accepts(string $url): bool
    {
        return $this->channel($url) !== null;
    }

    /**
     * The stream link points to the channel page on Twitch.
     * While a channel is live, its preview thumbnail is updated periodically. 640px wide is sharp on the 184px card at
     * any density and close to 2x on the widest sheet, with a quarter of the pixels of 1280px.
     */
    public function stream(string $url, CarbonInterface $endsAt): LiveStream
    {
        $channel = $this->channel($url);

        $link = "https://www.twitch.tv/{$channel}";

        return new LiveStream(LivePlatform::Twitch, $link, "https://static-cdn.jtvnw.net/previews-ttv/live_user_{$channel}-640x360.jpg", $endsAt, $this->players->for($link));
    }

    /**
     * Only links pointing to a user's Twitch channel can be a stream, which embed as a channel player.
     */
    private function channel(string $url): ?string
    {
        $parsed = Url::parse($url);
        $src = $parsed instanceof Url ? $this->embeds->embed($parsed)?->src : null;

        return preg_match('#[?&]channel=([A-Za-z0-9_]{3,25})(?:&|$)#', (string) $src, $matches) === 1 ? mb_strtolower($matches[1]) : null;
    }
}
