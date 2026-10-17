/// <reference types="node" />

import assert from 'node:assert/strict';
import { mock, test } from 'node:test';
import type { InfiniteScrollRef } from '@inertiajs/core';
import { createInertiaApp, InfiniteScroll, router } from '@inertiajs/react';
import React, { type ReactElement, type ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import Index from '../../resources/js/pages/notification/index';
import type {
    Commit,
    NotificationGroup,
    NotificationSyncResponse,
    User,
} from '../../resources/js/types';

const actor = {
    id: 'actor',
    name: 'Matthieu Napoli',
    username: 'mnapoli',
    lane: 2,
} as User;

const notification = {
    id: 'notification',
    group_id: 'notification',
    event_count: 1,
    type: 'replied',
    actor,
    actors: [actor],
    actors_count: 1,
    unread_count: 1,
    commit_id: 'reply',
    subject_id: 'parent',
    reason: null,
    created_at: '2026-10-16T11:51:00.000000Z',
    commit: {
        id: 'reply',
        body: 'Try this instead\n```php\nreturn round($score, 6);\n```',
        body_segments: [
            { type: 'text', text: 'Try this instead\n' },
            {
                type: 'block',
                language: 'php',
                html: '<span class="hl-keyword">return</span> round($score, 6);',
            },
        ],
    } as Omit<Commit, 'user'>,
} as NotificationGroup;

async function render(notifications: NotificationGroup[]): Promise<string> {
    const result = await createInertiaApp({
        page: {
            component: 'notification/index',
            props: {
                auth: {
                    user: { id: 'recipient', username: 'nunomaduro' },
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
                readAt="2026-10-16T12:00:00.000000Z"
            />
        ),
        render: renderToString,
        setup: ({ App, props }) => <App {...props} />,
    });

    assert.ok(result);

    return result.body.slice(result.body.indexOf('<article'));
}

void test('renders a code block highlighted in a notification', async () => {
    const body = await render([notification]);

    assert.match(body, /<pre[^>]*><span class="hl-keyword">return<\/span>/);
    assert.doesNotMatch(body, /```/);
    assert.equal(body.match(/<a\b/g)?.length, 1);
    assert.doesNotMatch(body, /<button\b/);
    assert.match(body, /<pre[^>]*tabindex="-1"/i);
});

for (const isFollowing of [false, true]) {
    void test(`keeps ${isFollowing ? 'unfollow' : 'follow-back'} separate from highlighted notification links`, async () => {
        const followed = {
            ...notification,
            id: 'follow-notification',
            group_id: 'follow-notification',
            type: 'followed',
            actor: { ...actor, is_followed: isFollowing },
            commit: null,
            commit_id: null,
            subject_id: null,
        } as NotificationGroup;
        const body = await render([notification, followed]);

        assert.match(body, /<pre[^>]*><span class="hl-keyword">return<\/span>/);
        assert.equal(body.match(/<a\b/g)?.length, 2);
        assert.equal(body.match(/<button\b/g)?.length, 1);
        assert.doesNotMatch(body, /<a\b[^>]*>(?:(?!<\/a>).)*<button\b/s);
        assert.match(
            body,
            new RegExp(
                `aria-label="${isFollowing ? 'Unfollow' : 'Follow'} @mnapoli"`,
            ),
        );
        assert.match(body, /followed you/);
        assert.doesNotMatch(body, /```/);
    });
}

type Element = ReactElement<Record<string, unknown>>;

function elements(node: ReactNode): Element[] {
    if (!React.isValidElement(node)) {
        return [];
    }

    const element = node as Element;

    return [
        element,
        ...React.Children.toArray(element.props.children as ReactNode).flatMap(
            elements,
        ),
    ];
}

// This seam executes Index and its completion callbacks with persistent hook
// state. It inspects React keys; it does not mount Inertia or simulate its DOM.
function inboxLifecycle(nextCursor: string | null = 'old-deep-cursor') {
    const slots: unknown[] = [];
    let cursor = 0;
    let applySync: (
        response: NotificationSyncResponse,
        generation: number,
    ) => void;
    let props = {
        auth: { unreadNotificationsCount: 0 },
        errors: {} as Record<string, string>,
        notifications: {
            data: [notification],
            next_cursor: nextCursor,
            prev_cursor: null,
        },
    };
    const visits: Parameters<typeof router.visit>[1][] = [];
    const visit = mock.method(
        router,
        'visit',
        (...[_href, options]: Parameters<typeof router.visit>) => {
            visits.push(options);
        },
    );
    const replace = mock.method(
        router,
        'replace',
        (options: {
            props: (current: typeof props) => typeof props;
            onError?: (errors: Record<string, string>) => void;
            onSuccess?: () => void;
        }) => {
            props = options.props(props);
            if (Object.keys(props.errors).length > 0) {
                options.onError?.(props.errors);
            } else {
                options.onSuccess?.();
            }
        },
    );
    const internals = (
        React as unknown as {
            __CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE: {
                H: unknown;
            };
        }
    ).__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
    const dispatcher = {
        useRef: (initial: unknown) => {
            const index = cursor++;
            slots[index] ??= { current: initial };

            return slots[index];
        },
        useState: (initial: unknown) => {
            const index = cursor++;
            if (!(index in slots)) {
                slots[index] = initial;
            }

            return [
                slots[index],
                (value: unknown) => {
                    slots[index] =
                        typeof value === 'function'
                            ? value(slots[index])
                            : value;
                },
            ];
        },
        useEffect: () => {},
        useLayoutEffect: () => {},
        useEffectEvent: (callback: typeof applySync) => {
            applySync = callback;

            return callback;
        },
        useSyncExternalStore: (_subscribe: unknown, snapshot: () => unknown) =>
            snapshot(),
    };
    const render = () => {
        const previous = internals.H;
        internals.H = dispatcher;
        cursor = 0;

        try {
            return elements(
                Index({
                    notifications: props.notifications,
                    filters: { type: 'all' },
                    readAt: '2026-10-16T12:00:00.000000Z',
                }),
            );
        } finally {
            internals.H = previous;
        }
    };

    return {
        render,
        visits,
        sync: (response: NotificationSyncResponse) => applySync(response, 0),
        attachScroll: (handle: InfiniteScrollRef) => {
            const ref = render().find(
                (element) => element.type === InfiniteScroll,
            )?.props.ref as React.RefObject<InfiniteScrollRef> | undefined;

            if (ref) {
                ref.current = handle;
            }
        },
        appendFinalPage: () => {
            props = {
                ...props,
                errors: { notice: 'Earlier action failed' },
                notifications: {
                    ...props.notifications,
                    data: [
                        ...props.notifications.data,
                        {
                            ...notification,
                            id: 'last-row',
                            group_id: 'last-row',
                        },
                    ],
                    next_cursor: null,
                },
            };
        },
        currentProps: () => props,
        swap: (errors: Record<string, string>, nextCursor: string | null) => {
            props = {
                ...props,
                errors,
                notifications: {
                    data: [{ ...notification, id: 'refreshed-notification' }],
                    next_cursor: nextCursor,
                    prev_cursor: null,
                },
            };
        },
        restore: () => {
            visit.mock.restore();
            replace.mock.restore();
        },
    };
}

for (const navigation of ['refresh', 'filter'] as const) {
    for (const completion of ['onError', 'onSuccess'] as const) {
        void test(`${navigation} resets the rendered scroll instance after ${completion}`, () => {
            const inbox = inboxLifecycle();
            const originalWindow = Object.getOwnPropertyDescriptor(
                globalThis,
                'window',
            );
            Object.defineProperty(globalThis, 'window', {
                configurable: true,
                value: { scrollTo: () => {} },
            });

            try {
                const before = inbox.render();
                const oldScroll = before.find(
                    (element) => element.type === InfiniteScroll,
                );
                assert.ok(oldScroll);

                if (navigation === 'refresh') {
                    inbox.sync({
                        head: [notification],
                        boundary: null,
                        visible: [],
                        missing_visible_group_ids: [],
                        unread_notifications_count: 0,
                    });
                } else {
                    const filter = before.find(
                        (element) =>
                            element.type === 'a' &&
                            element.props.href ===
                                '/notifications?type=replies',
                    );
                    assert.ok(filter);
                    (filter.props.onClick as (event: unknown) => void)({
                        button: 0,
                        preventDefault: () => {},
                    });
                }

                const visit = inbox.visits.at(-1);
                assert.ok(visit);
                assert.deepEqual(visit.reset, ['notifications']);
                assert.equal(visit.preserveState, true);
                assert.equal(
                    inbox
                        .render()
                        .find((element) => element.type === InfiniteScroll)
                        ?.key,
                    oldScroll.key,
                );

                const errors: Record<string, string> =
                    completion === 'onError'
                        ? { notice: 'Earlier action failed' }
                        : {};
                inbox.swap(errors, 'refreshed-cursor');
                assert.equal(
                    inbox
                        .render()
                        .find((element) => element.type === InfiniteScroll)
                        ?.key,
                    oldScroll.key,
                );
                const finish = visit[completion] as (result: unknown) => void;
                assert.equal(typeof finish, 'function');
                finish(
                    completion === 'onError' ? errors : { props: { errors } },
                );

                const nextScroll = inbox
                    .render()
                    .find((element) => element.type === InfiniteScroll);
                assert.ok(nextScroll);
                assert.notEqual(
                    nextScroll.key,
                    oldScroll.key,
                    'a completed reset must replace the old scroll data manager',
                );
            } finally {
                inbox.restore();
                if (originalWindow) {
                    Object.defineProperty(globalThis, 'window', originalWindow);
                } else {
                    Reflect.deleteProperty(globalThis, 'window');
                }
            }
        });
    }
}

void test('a superseded filter completion does not reset the current scroll instance', () => {
    const inbox = inboxLifecycle();

    try {
        const before = inbox.render();
        const oldScroll = before.find(
            (element) => element.type === InfiniteScroll,
        );
        assert.ok(oldScroll);
        const filter = before.find(
            (element) =>
                element.type === 'a' &&
                element.props.href === '/notifications?type=replies',
        );
        assert.ok(filter);
        const click = filter.props.onClick as (event: unknown) => void;
        click({ button: 0, preventDefault: () => {} });
        const superseded = inbox.visits.at(-1);
        click({ button: 0, preventDefault: () => {} });

        superseded?.onError?.({ notice: 'Earlier action failed' });
        assert.equal(
            inbox.render().find((element) => element.type === InfiniteScroll)
                ?.key,
            oldScroll.key,
        );
    } finally {
        inbox.restore();
    }
});

void test('safe poll reconciliation preserves the rendered scroll instance', () => {
    const inbox = inboxLifecycle();
    const originalDocument = Object.getOwnPropertyDescriptor(
        globalThis,
        'document',
    );
    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: { querySelectorAll: () => [] },
    });

    try {
        const oldScroll = inbox
            .render()
            .find((element) => element.type === InfiniteScroll);
        assert.ok(oldScroll);
        inbox.sync({
            head: [notification],
            boundary: { id: notification.id, group_id: notification.group_id },
            visible: [],
            missing_visible_group_ids: [],
            unread_notifications_count: 1,
        });
        assert.equal(
            inbox.render().find((element) => element.type === InfiniteScroll)
                ?.key,
            oldScroll.key,
        );
        assert.equal(inbox.visits.length, 0);
    } finally {
        inbox.restore();
        if (originalDocument) {
            Object.defineProperty(globalThis, 'document', originalDocument);
        } else {
            Reflect.deleteProperty(globalThis, 'document');
        }
    }
});

void test('complete sync resets a live manager after an error-bearing final-page append', () => {
    const inbox = inboxLifecycle();
    const originalDocument = Object.getOwnPropertyDescriptor(
        globalThis,
        'document',
    );
    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: { querySelectorAll: () => [] },
    });

    try {
        // Inertia advances this token only on success, so an error-bearing
        // append updates the paginator while this handle still reports true.
        inbox.attachScroll({
            hasNext: () => true,
            hasPrevious: () => false,
            fetchNext: () => {},
            fetchPrevious: () => {},
        });
        inbox.appendFinalPage();
        const appended = inbox.currentProps().notifications;
        assert.equal(appended.next_cursor, null);
        assert.equal(appended.data.length, 2);
        assert.notDeepEqual(inbox.currentProps().errors, {});

        inbox.sync({
            head: [notification],
            boundary: null,
            visible: [],
            missing_visible_group_ids: [],
            unread_notifications_count: 0,
        });

        assert.equal(
            inbox.visits.length,
            1,
            'a live scroll token requires a server-backed reset',
        );
        assert.deepEqual(inbox.visits[0]?.reset, ['notifications']);
        assert.equal(inbox.currentProps().notifications, appended);
    } finally {
        inbox.restore();
        if (originalDocument) {
            Object.defineProperty(globalThis, 'document', originalDocument);
        } else {
            Reflect.deleteProperty(globalThis, 'document');
        }
    }
});

void test('repeated complete polls keep an exhausted first-page scroll instance', () => {
    const inbox = inboxLifecycle(null);
    const originalDocument = Object.getOwnPropertyDescriptor(
        globalThis,
        'document',
    );
    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: { querySelectorAll: () => [] },
    });

    try {
        inbox.attachScroll({
            hasNext: () => false,
            hasPrevious: () => false,
            fetchNext: () => {},
            fetchPrevious: () => {},
        });
        const oldScroll = inbox
            .render()
            .find((element) => element.type === InfiniteScroll);
        assert.ok(oldScroll);

        for (let poll = 0; poll < 2; poll++) {
            inbox.sync({
                head: [notification],
                boundary: null,
                visible: [],
                missing_visible_group_ids: [],
                unread_notifications_count: 0,
            });
        }

        assert.equal(inbox.visits.length, 0);
        assert.equal(
            inbox.render().find((element) => element.type === InfiniteScroll)
                ?.key,
            oldScroll.key,
        );
    } finally {
        inbox.restore();
        if (originalDocument) {
            Object.defineProperty(globalThis, 'document', originalDocument);
        } else {
            Reflect.deleteProperty(globalThis, 'document');
        }
    }
});
