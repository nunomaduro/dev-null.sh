/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { CommitQuoteButton } from '../../resources/js/components/commit-quote-button';
import { TooltipProvider } from '../../resources/js/components/ui/tooltip';
import type { Commit } from '../../resources/js/types';

const commit = {
    id: 'quote-target',
    user_id: 'author',
    status: 'approved',
} as Commit;

for (const user of [null, { id: 'viewer' }]) {
    void test(`renders the quote control for ${user === null ? 'guests' : 'signed-in readers'}`, async () => {
        const result = await createInertiaApp<{
            auth: { user: { id: string } | null };
        }>({
            page: {
                component: 'quote',
                props: { auth: { user }, errors: {} },
                url: '/@author/quote-target',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                rescuedProps: [],
                rememberedState: {},
                flash: {},
            },
            resolve: () => () => <CommitQuoteButton commit={commit} />,
            render: renderToString,
            setup: ({ App, props }) => (
                <TooltipProvider>
                    <App {...props} />
                </TooltipProvider>
            ),
        });

        assert.ok(result);
        assert.match(result.body, /data-test="quote-button"/);
        assert.doesNotMatch(result.body, /data-test="quote-composer"/);
    });
}
