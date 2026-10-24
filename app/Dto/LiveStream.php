<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\LivePlatform;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class LiveStream implements Arrayable
{
    /**
     * The embed is the platform's own player, or null when embeds are turned off, so the stream only links out.
     */
    public function __construct(
        public LivePlatform $platform,
        public string $url,
        public string $thumbnailUrl,
        public CarbonInterface $endsAt,
        public ?Embed $embed,
    ) {
        //
    }

    /**
     * @return array{url: string, thumbnail_url: string, platform: 'youtube'|'twitch', ends_at: string, embed: array{provider: string, kind: string, src: string, aspect_ratio: string|null, height: int|null, poster: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
            'platform' => $this->platform->value,
            'ends_at' => $this->endsAt->toIso8601ZuluString(),
            'embed' => $this->embed?->toArray(),
        ];
    }
}
