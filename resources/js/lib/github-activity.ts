import type { ActivityBucket } from '@/types/activity';

/**
 * The whole commit is the command, whatever the case. Keep in sync with app/Services/GitHubActivity.php.
 */
export function isGitHubActivity(body: string): boolean {
    return body.trim().toLowerCase() === '/activity';
}

/**
 * Empty days for the year before the given day, from the Sunday 52 weeks earlier, like GitHub's calendar: the board
 * the graph is drawn on while it loads, so it takes the same space before and after.
 */
export function emptyYear(today: Date): ActivityBucket[] {
    const end = Date.UTC(
        today.getUTCFullYear(),
        today.getUTCMonth(),
        today.getUTCDate(),
    );
    const start = end - (364 + new Date(end).getUTCDay()) * 86_400_000;

    return Array.from(
        { length: (end - start) / 86_400_000 + 1 },
        (_, index) => ({
            date: new Date(start + index * 86_400_000)
                .toISOString()
                .slice(0, 10),
            count: 0,
            level: 0,
        }),
    );
}
