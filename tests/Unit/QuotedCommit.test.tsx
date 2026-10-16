/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { QuotedCommit } from '../../resources/js/components/quoted-commit';
import type {
    Poll,
    QuotedCommit as QuotedCommitData,
} from '../../resources/js/types';

const commit = {
    id: 'quoted',
    user_id: 'author',
    impression_token: null,
    impression_token_expires_at: null,
    body: "I'm leaking the Algorithm @nunomaduro\n```php\nreturn round($score, 6);\n```",
    body_segments: [
        { type: 'text', text: "I'm leaking the Algorithm @nunomaduro\n" },
        {
            type: 'block',
            language: 'php',
            html: '<span class="hl-keyword">return</span> round($score, 6);',
        },
    ],
    status: 'approved',
    created_at: '2026-10-16T11:51:00.000000Z',
    user: { id: 'author', name: 'Matthieu Napoli', username: 'mnapoli' },
} as QuotedCommitData;

const poll = {
    id: 'poll',
    closes_at: '2099-01-01T00:00:00.000000Z',
    is_closed: false,
    has_voted: false,
    total_votes: null,
    options: [
        { id: 'tabs', label: 'Tabs', votes_count: null, voted: false },
        { id: 'spaces', label: 'Spaces', votes_count: null, voted: false },
    ],
} satisfies Poll;

async function render(
    isPreview: boolean,
    quoted: QuotedCommitData = commit,
): Promise<string> {
    const result = await createInertiaApp({
        page: {
            component: 'quote',
            props: { auth: { user: null }, errors: {} },
            url: '/@author/quote',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => () => (
            <QuotedCommit commit={quoted} isPreview={isPreview} />
        ),
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });

    assert.ok(result);

    return result.body;
}

for (const isPreview of [false, true]) {
    void test(`renders a quoted code block highlighted ${isPreview ? 'in the composer' : 'in the log'}`, async () => {
        const body = await render(isPreview);

        assert.match(body, /<pre[^>]*><span class="hl-keyword">return<\/span>/);
        assert.doesNotMatch(body, /```/);
    });
}

void test('keeps the quote a single link', async () => {
    const body = await render(false);

    assert.equal(body.match(/<a\b/g)?.length, 1);
    assert.doesNotMatch(body, /<button\b/);
});

void test('shows the options of a quoted poll without letting anyone vote inside the quote', async () => {
    const body = await render(false, { ...commit, poll });

    assert.match(body, /data-test="commit-poll"/);
    assert.match(body, />Tabs</);
    assert.match(body, />Spaces</);
    assert.equal(body.match(/<a\b/g)?.length, 1);
    assert.doesNotMatch(body, /<(input|button|fieldset|label)\b/);
});

void test('shows the results of a quoted poll once the viewer may see them', async () => {
    const body = await render(true, {
        ...commit,
        poll: {
            ...poll,
            has_voted: true,
            total_votes: 4,
            options: [
                { id: 'tabs', label: 'Tabs', votes_count: 1, voted: false },
                { id: 'spaces', label: 'Spaces', votes_count: 3, voted: true },
            ],
        },
    });

    assert.match(body, />75<!-- -->%/);
    assert.match(body, /4 votes/);
});
