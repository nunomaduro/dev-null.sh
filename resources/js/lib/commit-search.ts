import CommitSearchController from '@/actions/App/Http/Controllers/CommitSearchController';
import type { Commit } from '@/types';

export const suggestedQueries = [
    'shipping a side project',
    'debugging in production',
    'testing strategies that actually work',
    'learning a new programming language',
    'speeding up a slow app',
    'working with AI coding tools',
];

/**
 * Thrown when the server asks the client to slow down, so it can say so instead of claiming nothing matched.
 */
export class TooManySearches extends Error {}

/**
 * Thrown when the search itself is down, so the client can say so instead of claiming nothing matched.
 */
export class SearchUnavailable extends Error {}

export function searchCommits(
    term: string,
    limit?: number,
    signal?: AbortSignal,
): Promise<Commit[]> {
    return fetch(
        CommitSearchController.index({
            query: limit === undefined ? { q: term } : { q: term, limit },
        }).url,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal,
        },
    )
        .then((response) => {
            if (response.status === 429) {
                throw new TooManySearches();
            }

            if (response.status === 503) {
                throw new SearchUnavailable();
            }

            if (!response.ok) {
                throw new Error(`Commit search failed: ${response.status}`);
            }

            return response.json();
        })
        .then((data: { commits: Commit[] }) => data.commits);
}
