/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { CommitStarButton } from '../../resources/js/components/commit-star-button';
import { TooltipProvider } from '../../resources/js/components/ui/tooltip';
import type { Commit } from '../../resources/js/types';

async function render(
    user: { id: string } | null,
    commit: Partial<Commit> = {},
): Promise<string> {
    const result = await createInertiaApp<{
        auth: { user: { id: string } | null };
    }>({
        page: {
            component: 'star',
            props: { auth: { user }, errors: {} },
            url: '/@author/commit',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => () => (
            <CommitStarButton
                commit={
                    {
                        id: 'commit',
                        user_id: 'author',
                        status: 'approved',
                        starred: false,
                        stars_count: 2,
                        ...commit,
                    } as Commit
                }
            />
        ),
        render: renderToString,
        setup: ({ App, props }) => (
            <TooltipProvider>
                <App {...props} />
            </TooltipProvider>
        ),
    });

    assert.ok(result);

    return result.body;
}

for (const status of ['approved', 'pending', 'rejected'] as const) {
    void test(`authors see only their ${status} commit's star count`, async () => {
        const body = await render({ id: 'author' }, { status });

        assert.match(body, /data-test="star-count-readonly"/);
        assert.match(body, /aria-label="2 stars"/);
        assert.doesNotMatch(body, /<button\b/);
    });
}

void test('authors see an accessible empty star count', async () => {
    const body = await render({ id: 'author' }, { stars_count: 0 });

    assert.match(body, /aria-label="No stars yet"/);
    assert.doesNotMatch(body, /<button\b/);
});

for (const user of [null, { id: 'viewer' }]) {
    void test(`approved commits keep the star control for ${user === null ? 'guests' : 'other readers'}`, async () => {
        const body = await render(user);

        assert.match(body, /data-test="star-commit-button"/);
        assert.match(body, /aria-pressed="false"/);
        assert.match(body, /aria-label="Star commit, 2 stars"/);
        assert.doesNotMatch(body, /data-test="star-count-readonly"/);
    });
}

for (const status of ['pending', 'rejected'] as const) {
    void test(`other readers cannot star a ${status} commit`, async () => {
        const body = await render({ id: 'viewer' }, { status });

        assert.doesNotMatch(
            body,
            /data-test="star-(?:commit-button|count-readonly)"/,
        );
    });
}

for (const status of ['approved', 'pending', 'rejected'] as const) {
    void test(`authors see their ${status} reply's read-only star count`, async () => {
        const body = await render(
            { id: 'author' },
            { status, parent_id: 'parent' },
        );

        assert.match(body, /data-test="star-count-readonly"/);
        assert.match(body, /aria-label="2 stars"/);
        assert.doesNotMatch(body, /<button\b/);
    });
}
