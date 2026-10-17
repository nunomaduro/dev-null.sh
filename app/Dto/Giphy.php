<?php

declare(strict_types=1);

namespace App\Dto;

/** A GIF picked from GIPHY; URLs kept exactly as its API returned them. */
final readonly class Giphy
{
    public function __construct(
        public string $id,
        public string $url,
        public string $pageUrl,
        public ?string $title,
        public ?string $username,
        public int $width,
        public int $height,
    ) {
        //
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toColumns(): array
    {
        return [
            'giphy_id' => $this->id,
            'giphy_url' => $this->url,
            'giphy_page_url' => $this->pageUrl,
            'giphy_title' => $this->title,
            'giphy_username' => $this->username,
            'giphy_width' => $this->width,
            'giphy_height' => $this->height,
        ];
    }
}
