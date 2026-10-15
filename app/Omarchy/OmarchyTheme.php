<?php

declare(strict_types=1);

namespace App\Omarchy;

final readonly class OmarchyTheme
{
    /**
     * @param  array<string, string>  $tokens
     */
    public function __construct(
        public string $name,
        public string $mode,
        public ?string $font,
        public array $tokens,
    ) {}
}
