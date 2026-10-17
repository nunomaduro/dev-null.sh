/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import Index from '../../resources/js/pages/notification/index';
import type { NotificationGroup, User } from '../../resources/js/types';

const timestamp = '2026-10-16T12:00:00Z';
const actor: User = {
    id: 'actor',
    username: 'quoter',
    name: 'Quoter',
    avatar_url: null,
    bio: null,
    location: null,
    website: null,
    company: null,
    has_sponsors_listing: false,
    timezone: null,
    has_github_account: true,
    lane: 0,
    created_at: timestamp,
    updated_at: timestamp,
};
const quote: NotificationGroup = {
    id: 'notice',
    recipient_id: 'recipient',
    actor_id: actor.id,
    commit_id: 'quote-commit',
    subject_id: null,
    reason: null,
    read_at: timestamp,
    group_id: 'notice',
    type: 'quoted',
    actor: { ...actor, is_followed: false },
    actors: [actor],
    actors_count: 1,
    event_count: 1,
    unread_count: 0,
    created_at: timestamp,
    updated_at: timestamp,
    commit: {
        id: 'quote-commit',
        user_id: actor.id,
        parent_id: null,
        body: 'My quote commentary',
        body_segments: [{ type: 'text', text: 'My quote commentary' }],
        preview_segments: null,
        status: 'approved',
        created_at: timestamp,
        updated_at: timestamp,
        stars_count: 0,
        starred: false,
        quoted_commit_id: 'quoted-commit',
    },
};

async function renderInbox(notifications: NotificationGroup[]) {
    const result = await createInertiaApp({
        page: {
            component: 'notification/index',
            props: {
                auth: {
                    user: { id: 'recipient', username: 'recipient' },
                    log: {},
                },
                errors: {},
            },
            url: '/notifications',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            rescuedProps: [],
            rememberedState: {},
            flash: {},
        },
        resolve: () => () => (
            <Index
                notifications={{
                    data: notifications,
                    next_cursor: null,
                    prev_cursor: null,
                }}
                filters={{ type: 'all' }}
                readAt={timestamp}
            />
        ),
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });

    assert.ok(result);
    return result.body.replaceAll('<!-- -->', '');
}

void test('renders quote notifications with their label and quoting commit destination', async () => {
    const html = await renderInbox([quote]);

    assert.match(html, /quoted your commit/);
    assert.match(html, /href="\/@quoter\/commits\/quote-commit"/);
    assert.match(html, /My quote commentary/);
    assert.match(
        html,
        /<a[^>]*href="\/notifications\?type=quotes"[^>]*><span[^>]*>Quotes<\/span><\/a>/,
    );
});

void test('includes quotes in the unfiltered empty inbox message', async () => {
    assert.match(await renderInbox([]), /replies, quotes, mentions/);
});

void test('renders a follow-back control outside the follower profile link', async () => {
    const follow: NotificationGroup = {
        ...quote,
        type: 'followed',
        commit_id: null,
        subject_id: null,
        commit: null,
        event_count: 2,
        actors_count: 2,
    };
    const html = await renderInbox([follow]);

    assert.match(html, /Quoter<\/span> and 1 other followed you/);
    assert.match(html, /aria-expanded="false"[^>]*>.*2 followers/s);
    assert.match(html, /<button[^>]*aria-label="Follow @quoter"/);
    assert.doesNotMatch(html, /<a\b[^>]*>(?:(?!<\/a>).)*<button\b/s);
    assert.match(
        await renderInbox([
            { ...follow, actor: { ...follow.actor, is_followed: true } },
        ]),
        /aria-label="Unfollow @quoter"/,
    );
});

void test('renders notification code without altering emoji shortcodes or nesting interactive controls', async () => {
    const html = await renderInbox([
        {
            ...quote,
            commit: {
                ...quote.commit,
                body: '`echo ":fire:"`',
                body_segments: [{ type: 'code', code: 'echo ":fire:"' }],
            },
        },
    ]);

    assert.match(html, /<code[^>]*>echo &quot;:fire:&quot;<\/code>/);
    assert.doesNotMatch(html, /Copy code/);
});

void test('renders highlighted notification blocks without a nested copy button', async () => {
    const html = await renderInbox([
        {
            ...quote,
            commit: {
                ...quote.commit,
                body: '```php\nreturn ":fire:";\n```',
                body_segments: [
                    {
                        type: 'block',
                        language: 'php',
                        html: '<span class="hl-keyword">return</span> &quot;:fire:&quot;;',
                    },
                ],
            },
        },
    ]);

    assert.match(
        html,
        /<pre[^>]*><span class="hl-keyword">return<\/span> &quot;:fire:&quot;;<\/pre>/,
    );
    assert.doesNotMatch(html, /Copy code/);
});
