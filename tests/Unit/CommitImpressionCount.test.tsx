/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { CommitCard } from '../../resources/js/components/commit-card';
import type { Commit } from '../../resources/js/types';

const commit = {
    id: '00000000-0000-4000-8000-000000000001',
    user_id: 'author',
    impression_token: null,
    impression_token_expires_at: null,
    parent_id: null,
    body: 'Viewport impressions are now visible.',
    body_segments: [
        { type: 'text', text: 'Viewport impressions are now visible.' },
    ],
    preview_segments: null,
    status: 'approved',
    created_at: '2026-10-16T12:00:00.000000Z',
    quoted_commit_id: null,
    stars_count: 0,
    pasteis_count: 0,
    starred: false,
    user: { id: 'author', username: 'author', name: 'Author', lane: 0 },
} as Commit;

async function renderCard(
    count: number,
    user: { id: string } | null,
    status: Commit['status'] = 'approved',
) {
    const result = await createInertiaApp<{
        auth: { user: { id: string } | null };
    }>({
        page: {
            component: 'commit/show',
            props: { auth: { user }, errors: {} },
            url: '/@author/commits/00000000-0000-4000-8000-000000000001',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => () => (
            <CommitCard
                commit={{ ...commit, impressions_count: count, status }}
            />
        ),
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });
    assert.ok(result);
    return result.body;
}

for (const user of [null, { id: 'reader' }, { id: 'author' }]) {
    void test(`shows the server's impression count to ${user?.id ?? 'guests'}`, async () => {
        const body = await renderCard(1_234, user);
        assert.match(body, /data-test="commit-impressions"/);
        assert.match(body, /aria-label="1,234 impressions"/);
        assert.match(body, />1,234<\/span>/);
    });
}

void test('shows zero impressions and uses a singular accessible label for one', async () => {
    assert.match(await renderCard(0, null), /aria-label="0 impressions"/);
    assert.match(await renderCard(1, null), /aria-label="1 impression"/);
});

void test('keeps large counters compact while exposing the exact total', async () => {
    const body = await renderCard(12_345, null);
    assert.match(body, /aria-label="12,345 impressions"/);
    assert.match(body, />12\.3K<\/span>/);
});

void test('does not show impression counters on a pending commit', async () => {
    assert.doesNotMatch(
        await renderCard(0, { id: 'author' }, 'pending'),
        /data-test="commit-impressions"/,
    );
});
