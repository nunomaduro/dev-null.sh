<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Commit;
use Illuminate\Container\Attributes\Scoped;

/**
 * Scoped, like the CodeSpans it relies on, so that a page of commits shares one.
 */
#[Scoped]
final readonly class CommitBodies
{
    public function __construct(private CodeSpans $codeSpans)
    {
        //
    }

    /**
     * The body split into prose, inline code and highlighted code blocks, for rendering, and so is its excerpt when it
     * is too long to show whole in a list.
     *
     * @return array{body_segments: list<array<string, string|null>>, preview_segments: list<array<string, string|null>>|null}
     */
    public function segments(string $body): array
    {
        $excerpt = $this->excerpt($body);

        return [
            'body_segments' => $this->codeSpans->highlight($body),
            'preview_segments' => $excerpt === null ? null : $this->codeSpans->highlight($excerpt),
        ];
    }

    /**
     * Ends lines the way browsers display them rather than submit them, so a line break counts as one character, then
     * collapses runs of blank lines in the prose into one, so nobody pads a commit out. Code keeps its blank lines.
     */
    public function normalize(string $body): string
    {
        return $this->codeSpans->mapProse(str_replace(["\r\n", "\r"], "\n", $body), static fn (string $prose): string => (string) preg_replace('/(?:[ \t]*\n){3,}/', "\n\n", $prose));
    }

    /**
     * The start of a body too long to show whole in a list, or null when it fits: at most Commit::PREVIEW_LENGTH
     * characters and Commit::PREVIEW_LINES lines, cut at a word boundary. Code is never cut through: the excerpt
     * ends before it, unless the body starts with it, in which case a code block keeps its first lines.
     */
    public function excerpt(string $body): ?string
    {
        $body = str_replace("\r\n", "\n", $body);
        $limit = $this->limit($body);

        if ($limit === null) {
            return null;
        }

        $excerpt = '';

        foreach ($this->codeSpans->split($body) as $index => $part) {
            $length = mb_strlen($part);

            if ($length <= $limit) {
                $excerpt .= $part;
                $limit -= $length;

                continue;
            }

            $isFirst = mb_trim($excerpt) === '';

            $excerpt .= match (true) {
                $index % 2 === 0 => $this->cutProse($part, $limit, $isFirst),
                $isFirst => $this->cutCode($part, $limit),
                default => '',
            };

            break;
        }

        $excerpt = mb_rtrim($excerpt);

        // There is always more after the excerpt. After a code block, the ellipsis goes on a line of its own.
        return str_ends_with($excerpt, '```') ? $excerpt."\n…" : $excerpt.'…';
    }

    /**
     * How many characters of the body fit in the excerpt, or null when the whole body does.
     */
    private function limit(string $body): ?int
    {
        $lines = explode("\n", $body, Commit::PREVIEW_LINES + 1);

        if (count($lines) > Commit::PREVIEW_LINES) {
            return min(Commit::PREVIEW_LENGTH, mb_strlen(implode("\n", array_slice($lines, 0, Commit::PREVIEW_LINES))));
        }

        return mb_strlen($body) > Commit::PREVIEW_LENGTH ? Commit::PREVIEW_LENGTH : null;
    }

    /**
     * Cuts at the last whitespace that fits. A word too long to fit is left out whole, unless nothing comes before it.
     */
    private function cutProse(string $prose, int $limit, bool $isFirst): string
    {
        $cut = mb_substr($prose, 0, $limit);

        if (preg_match('/\s/u', mb_substr($prose, $limit, 1)) === 1) {
            return $cut;
        }

        if (preg_match('/\A(.*)\s/su', $cut, $matches) === 1) {
            return $matches[1];
        }

        return $isFirst ? $cut : '';
    }

    /**
     * Keeps the first lines of a code block that fit, and closes it again. Inline code is cut short.
     */
    private function cutCode(string $code, int $limit): string
    {
        if (! str_starts_with($code, '```')) {
            return mb_substr($code, 0, max($limit - 1, 1)).'`';
        }

        $lines = explode("\n", $code);
        $fence = (string) preg_replace('/[^`].*\z/s', '', $lines[0]);
        $kept = [array_shift($lines)];
        $limit -= mb_strlen($kept[0]) + mb_strlen($fence) + 1;

        foreach (array_slice($lines, 0, -1) as $line) {
            if (mb_strlen($line) + 1 > $limit) {
                if (count($kept) === 1) {
                    $kept[] = mb_substr($line, 0, max($limit - 1, 1));
                }

                break;
            }

            $kept[] = $line;
            $limit -= mb_strlen($line) + 1;
        }

        return implode("\n", [...$kept, $fence]);
    }
}
