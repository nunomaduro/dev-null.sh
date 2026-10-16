<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A bot reply chain: each reply answers the previous one, and ":name" becomes the author's name.
 */
final readonly class EasterEgg
{
    /**
     * @param  list<string>  $replies
     */
    public function __construct(
        public string $bot,
        public string $bio,
        public array $replies,
    ) {
        //
    }
}
