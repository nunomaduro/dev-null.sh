/// <reference types="node" />
import assert from 'node:assert/strict';
import { afterAll, test, vi } from 'vite-plus/test';
import {
    SearchUnavailable,
    searchCommits,
    TooManySearches,
} from '../../resources/js/lib/commit-search';

function respond(status: number, body: unknown = {}): void {
    vi.stubGlobal('fetch', () =>
        Promise.resolve(
            new Response(JSON.stringify(body), {
                status,
                headers: { 'Content-Type': 'application/json' },
            }),
        ),
    );
}

afterAll(() => vi.unstubAllGlobals());

test('returns the commits the server found', async () => {
    respond(200, { commits: [{ id: 'found' }] });

    assert.deepEqual(await searchCommits('deploys'), [{ id: 'found' }]);
});

test('tells a search that is down apart from one that found nothing', async () => {
    respond(503, { message: 'Commit search is unavailable right now.' });

    await assert.rejects(
        searchCommits('deploys'),
        (error: unknown) => error instanceof SearchUnavailable,
    );
});

test('tells a throttled search apart from one that is down', async () => {
    respond(429);

    await assert.rejects(
        searchCommits('deploys'),
        (error: unknown) =>
            error instanceof TooManySearches &&
            !(error instanceof SearchUnavailable),
    );
});
