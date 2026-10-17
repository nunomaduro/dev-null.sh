/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import {
    ThreadActions,
    ThreadReplies,
    useThread,
} from '../../resources/js/components/commit-thread';
import { TooltipProvider } from '../../resources/js/components/ui/tooltip';
import type { Commit } from '../../resources/js/types';

const reply = (id: string): Commit =>
    ({
        id,
        user_id: 'author',
        parent_id: 'parent',
        quoted_commit_id: null,
        created_at: new Date().toISOString(),
        status: 'approved',
        body_segments: [{ type: 'text', text: `Visible reply ${id}` }],
        replies_count: 0,
        user: { id: 'author', name: 'Author', username: 'author', lane: 1, timezone: null },
    }) as Commit;

async function renderThread(count: number) {
    const commit = {
        ...reply('parent'),
        replies_count: count,
        reply_preview: {
            data: Array.from({ length: Math.min(count, 1) }, (_, i) =>
                reply(String(i)),
            ),
            next_cursor: count > 1 ? 'older-replies' : null,
            prev_cursor: null,
        },
    };

    function Preview() {
        const thread = useThread(commit);
        return (
            <TooltipProvider>
                <ThreadActions thread={thread} />
                <ThreadReplies thread={thread} depth={0} />
            </TooltipProvider>
        );
    }

    const result = await createInertiaApp({
        page: {
            component: 'test',
            props: { auth: { user: null }, errors: {} },
            url: '/',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => Preview,
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });

    assert.ok(result);
    return result.body;
}

for (const count of [0, 1, 2, 5]) {
    void test(`shows ${Math.min(count, 1)} replies immediately out of ${count}`, async () => {
        const html = await renderThread(count);
        assert.equal(
            (html.match(/Visible reply /g) ?? []).length,
            Math.min(count, 1),
        );
        assert.doesNotMatch(html, /data-test="toggle-replies-button"/);
        assert.equal(
            html.includes('data-test="more-replies-button"'),
            count > 1,
        );
        if (count > 1) assert.match(html, /Show more/);
    });
}
