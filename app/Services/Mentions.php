<?php

declare(strict_types=1);

namespace App\Services;

final readonly class Mentions
{
    private const string Pattern = '/(?<![\p{L}\p{N}_.\/@-])@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})(?![A-Za-z0-9_@\/-]|\.[A-Za-z0-9])/u';

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
        preg_match_all(self::Pattern, $this->codeSpans->withoutCode($text), $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }

    /**
     * Finds where each "@username" is in the text, by the same rules and also skipping code, so a client can link it
     * to the profile like the web does. The username keeps its case, and the offset and length count characters in
     * the whole text and include the "@".
     *
     * @return list<array{username: string, offset: int, length: int}>
     */
    public function positions(string $text): array
    {
        $positions = [];
        $offset = 0;

        foreach ($this->codeSpans->split($text) as $index => $part) {
            if ($index % 2 === 0) {
                preg_match_all(self::Pattern, $part, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

                foreach ($matches as $match) {
                    $positions[] = [
                        'username' => $match[1][0],
                        'offset' => $offset + mb_strlen(mb_strcut($part, 0, $match[0][1])),
                        'length' => mb_strlen($match[0][0]),
                    ];
                }
            }

            $offset += mb_strlen($part);
        }

        return $positions;
    }
}
