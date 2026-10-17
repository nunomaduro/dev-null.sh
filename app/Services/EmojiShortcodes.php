<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

final readonly class EmojiShortcodes
{
    /**
     * @var array<string, string>
     */
    private array $map;

    public function __construct(private CodeSpans $codeSpans)
    {
        $this->map = $this->load();
    }

    /**
     * Replaces :heart, :like, and the rest with their emoji, leaving a shortcode inside a
     * longer word (like :hearted), glued to the word before it (like status:ok) or in code untouched. Mirrors the frontend's inline replacement.
     */
    public function replace(string $text): string
    {
        $pattern = $this->pattern();

        return $this->codeSpans->mapProse(
            $text,
            fn (string $prose): string => (string) preg_replace_callback($pattern, $this->replaceMatch(...), $prose),
        );
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        /** @var array<string, string> $map */
        $map = File::json(resource_path('js/lib/emoji-shortcodes.json'));

        return $map;
    }

    /**
     * Longest names first so a shortcode never shadows a longer one.
     */
    private function pattern(): string
    {
        $names = array_keys($this->map);

        usort($names, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        $names = array_map(static fn (string $name): string => preg_quote($name, '/'), $names);

        return '/(?<![A-Za-z0-9_]):('.implode('|', $names).'):?(?![A-Za-z0-9_])/';
    }

    /**
     * @param  array<int|string, string>  $matches
     */
    private function replaceMatch(array $matches): string
    {
        return $this->map[$matches[1]];
    }
}
