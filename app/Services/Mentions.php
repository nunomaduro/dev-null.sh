<?php

declare(strict_types=1);

namespace App\Services;

final readonly class Mentions
{
    public function __construct(private CodeSpans $codeSpans)
    {
        //
    }

    /**
     * Finds the GitHub usernames mentioned as "@username" in the text, lowercased and once each, in order. A mention
     * can't follow a letter, digit, "_", ".", "/", "@" or "-" (so emails are not mentions), and can't run into a path
     * or a domain (so "@inertiajs/react" and "@vue.js" are not mentions either), and a mention in code doesn't count
     * (so "@Override" or "@param" never notify anyone). Keep the rules in sync with
     * resources/js/components/linkified-text.tsx.
     *
     * @return list<string>
     */
    public function usernames(string $text): array
    {
        if (! str_contains($text, '@')) {
            return [];
        }

        preg_match_all(
            '/(?<![\p{L}\p{N}_.\/@-])@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})(?![A-Za-z0-9_@\/-]|\.[A-Za-z0-9])/u',
            $this->codeSpans->withoutCode($text),
            $matches,
        );

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }
}
