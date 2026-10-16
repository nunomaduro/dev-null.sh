<?php

declare(strict_types=1);

namespace App\Services;

use Dom\HTMLDocument;

final readonly class GitHubActivity
{
    /**
     * The whole commit is the command, whatever the case. Anything else in the text is a different commit. Keep in sync
     * with resources/js/lib/github-activity.ts.
     */
    public function matches(string $body): bool
    {
        return mb_strtolower(mb_trim($body)) === '/activity';
    }

    /**
     * The days of the contribution calendar GitHub draws on a profile, from its public contributions page: each day's
     * date, its number of contributions, and the 0 to 4 level GitHub shades it with. Null when the page no longer has
     * the calendar it used to, so a change on GitHub's side shows as unavailable rather than as an empty year.
     *
     * @return list<array{date: string, count: int, level: int}>|null
     */
    public function parse(string $html): ?array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $counts = [];

        foreach ($document->querySelectorAll('tool-tip[for]') as $tooltip) {
            $counts[(string) $tooltip->getAttribute('for')] = $this->count($tooltip->textContent ?? '');
        }

        $days = [];

        foreach ($document->querySelectorAll('td.ContributionCalendar-day[data-date]') as $cell) {
            $date = (string) $cell->getAttribute('data-date');
            $level = (int) $cell->getAttribute('data-level');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $level < 0 || $level > 4) {
                return null;
            }

            $days[] = ['date' => $date, 'count' => $counts[(string) $cell->getAttribute('id')] ?? 0, 'level' => $level];
        }

        if ($days === []) {
            return null;
        }

        usort($days, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $days;
    }

    /**
     * "1,234 contributions on October 12th.", "1 contribution on …" or "No contributions on …".
     */
    private function count(string $label): int
    {
        return preg_match('/^\s*([\d,]+)\s+contributions?\b/', $label, $matches) === 1
            ? (int) str_replace(',', '', $matches[1])
            : 0;
    }
}
