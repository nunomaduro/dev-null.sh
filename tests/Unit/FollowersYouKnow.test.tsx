/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { FollowersYouKnow } from '../../resources/js/components/followers-you-know';
import type { User } from '../../resources/js/types';

const person = (username: string) =>
    ({ id: username, username, name: username, avatar_url: null }) as User;

async function render(usernames: string[], count: number): Promise<string> {
    const result = await createInertiaApp({
        page: {
            component: 'profile',
            props: { auth: { user: { id: 'viewer' } }, errors: {} },
            url: '/@octocat',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => () => (
            <FollowersYouKnow
                user={{ username: 'octocat' }}
                preview={{ users: usernames.map(person), count }}
            />
        ),
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });

    assert.ok(result);

    const sentence = /<p\b[^>]*>(.*?)<\/p>/s.exec(result.body)?.[1] ?? '';

    return sentence.replace(/<!-- -->/g, '').replace(/<[^>]+>/g, '');
}

void test('nothing shows when the viewer knows none of the followers', async () => {
    assert.equal(await render([], 0), '');
});

void test('one follower the viewer knows is named alone', async () => {
    assert.equal(await render(['alice'], 1), 'Followed by @alice');
});

void test('two followers the viewer knows are joined with and', async () => {
    assert.equal(
        await render(['alice', 'bob'], 2),
        'Followed by @alice and @bob',
    );
});

void test('a third follower the viewer knows is counted in the singular', async () => {
    assert.equal(
        await render(['alice', 'bob', 'carol'], 3),
        'Followed by @alice, @bob and 1 other you follow',
    );
});

void test('more followers the viewer knows are counted', async () => {
    assert.equal(
        await render(['alice', 'bob', 'carol'], 9),
        'Followed by @alice, @bob and 7 others you follow',
    );
});
