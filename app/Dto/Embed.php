<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\EmbedKind;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, string|int|null>
 */
final readonly class Embed implements Arrayable
{
    private function __construct(
        public string $provider,
        public EmbedKind $kind,
        public string $src,
        public ?string $aspectRatio,
        public ?int $height,
        public ?string $poster = null,
    ) {
        //
    }

    /**
     * The poster stands in for the player until it is played, for when the link's own preview has no image.
     *
     * @param  array<string, string|int>  $query
     */
    public static function video(string $provider, string $src, array $query = [], string $aspectRatio = '16 / 9', ?string $poster = null): self
    {
        return new self($provider, EmbedKind::Video, self::withQuery($src, $query), $aspectRatio, null, $poster);
    }

    /**
     * @param  array<string, string|int>  $query
     */
    public static function fixed(string $provider, EmbedKind $kind, string $src, int $height, array $query = []): self
    {
        return new self($provider, $kind, self::withQuery($src, $query), null, $height);
    }

    /**
     * @return array{provider: string, kind: string, src: string, aspect_ratio: string|null, height: int|null, poster: string|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'kind' => $this->kind->value,
            'src' => $this->src,
            'aspect_ratio' => $this->aspectRatio,
            'height' => $this->height,
            'poster' => $this->poster,
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private static function withQuery(string $src, array $query): string
    {
        return $query === [] ? $src : $src.'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }
}
