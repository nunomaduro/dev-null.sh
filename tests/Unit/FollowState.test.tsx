/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { afterAll, afterEach, test, vi } from 'vite-plus/test';
import type { Profile, SuggestedUser, User } from '../../resources/js/types';

type Props = Record<string, unknown> & { children?: ReactNode };

let showPreview = false;
let component = 'user/show';
let log: string | undefined;
let loadedCommits: { data: { id: string }[] } | undefined;
let suggestedUsers: SuggestedUser[] | undefined;
type VisitOptions = {
    only?: string[];
    headers?: Record<string, string>;
    async?: boolean;
    preserveState?: boolean;
    preserveScroll?: boolean;
    onHttpException?: (response: { status: number }) => boolean | void;
    onSuccess?: () => void;
    onError?: () => void;
    onNetworkError?: () => void;
    onCancel?: () => void;
    onFinish?: () => void;
    onBeforeUpdate?: () => void;
};

const visits: (VisitOptions & { method?: string })[] = [];
const reloads: VisitOptions[] = [];
const navigateListeners: (() => void)[] = [];
const timers: (() => void)[] = [];
const toasts: string[] = [];

vi.doMock('react', () => ({
    ...React,
    useEffect: () => {},
    useLayoutEffect: () => {},
    useRef: (initial: unknown) => ({ current: initial }),
    useState: (initial: unknown) => [
        typeof initial === 'function'
            ? initial()
            : initial === null && showPreview
              ? { left: 0, top: 0 }
              : initial,
        () => {},
    ],
    useSyncExternalStore: (_subscribe: unknown, read: () => unknown) => read(),
}));
vi.doMock('react-dom', async () => ({
    ...(await vi.importActual('react-dom')),
    createPortal: (children: ReactNode) => children,
}));
vi.doMock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    usePage: () => ({
        component,
        props: {
            auth: { user: { id: 'viewer' } },
            log,
            commits: loadedCommits,
            showsWhoToFollow: true,
            suggestedUsers,
        },
    }),
    router: {
        flushAll: () => {},
        visit: (url: { method: string }, options: VisitOptions) =>
            visits.push({ ...options, method: url.method }),
        reload: (options: VisitOptions) => reloads.push(options),
        on: (event: string, listener: () => void) => {
            if (event === 'navigate') {
                navigateListeners.push(listener);
            }

            return () =>
                navigateListeners.splice(navigateListeners.indexOf(listener), 1);
        },
        replaceProp: (
            _key: string,
            update: (
                current: SuggestedUser[] | undefined,
            ) => SuggestedUser[] | undefined,
        ) => (suggestedUsers = update(suggestedUsers)),
    },
}));

vi.doMock('sonner', () => ({
    toast: {
        error: (message: string) => toasts.push(message),
        success: (message: string) => toasts.push(message),
    },
}));

vi.doMock('../../resources/js/lib/chat', async () => ({
    ...(await vi.importActual('../../resources/js/lib/chat')),
    blockPartner: async () => true,
}));

const { FollowButton } =
    await import('../../resources/js/components/follow-button');
const { UserPreview } =
    await import('../../resources/js/components/user-preview');
const { SuggestedUserRow, WhoToFollow, WhoToFollowInLog } =
    await import('../../resources/js/components/who-to-follow');
const { refreshSharedState } =
    await import('../../resources/js/hooks/use-shared-state');
const { UserSafetyMenu } =
    await import('../../resources/js/components/user-safety-menu');
const { ConversationActions } =
    await import('../../resources/js/components/chat/conversation-actions');

vi.stubGlobal('window', {
    matchMedia: () => ({ matches: false }),
    setTimeout: (callback: () => void) => timers.push(callback),
});
vi.stubGlobal('document', { body: {}, cookie: '' });
afterAll(() => vi.unstubAllGlobals());
afterEach(() => {
    component = 'user/show';
    log = undefined;
    loadedCommits = undefined;
    suggestedUsers = undefined;
    refreshSharedState(
        new Map<string, boolean | number | string>([
            ['who-to-follow-dismissing', 0],
            ['who-to-follow-top-up', 'idle'],
            ['who-to-follow-dismissed', false],
        ]),
    );
});

function find(
    node: ReactNode,
    matches: (props: Props) => boolean,
): ReactElement<Props> | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = find(child, matches);

            if (found) {
                return found;
            }
        }

        return null;
    }

    if (!React.isValidElement<Props>(node)) {
        return null;
    }

    return matches(node.props) ? node : find(node.props.children, matches);
}

function render(element: ReactElement<Props>): ReactNode {
    return (element.type as (props: Props) => ReactNode)(element.props);
}

function suggestion(username: string): SuggestedUser {
    return {
        id: username,
        name: username,
        username,
        avatar_url: null,
        lane: 1,
        timezone: null,
        show_local_time: false,
        followed_by_following_count: 0,
        followers_count: 0,
    } as unknown as SuggestedUser;
}

function suggestionLabel(user: SuggestedUser): unknown {
    const button = find(
        SuggestedUserRow({ user }),
        (props) => props['data-test'] === 'suggested-follow-button',
    );
    assert.ok(button);

    return find(render(button), (props) => 'aria-label' in props)?.props[
        'aria-label'
    ];
}

function isSuggested(user: SuggestedUser): boolean {
    const row = SuggestedUserRow({ user }) as ReactElement<Props>;

    return row.props.inert !== true;
}

function blockFromSafetyMenu(user: SuggestedUser): void {
    const block = find(
        UserSafetyMenu({ user, isMuting: false, isBlocking: false }),
        (props) => props.children === 'Block',
    );
    assert.ok(block);
    (block.props.onClick as () => void)();
    visits.at(-1)?.onSuccess?.();
}

function previewFollowButton(
    user: SuggestedUser,
    isFollowing = false,
    onChange?: (data: { user: Profile }) => void,
): ReactElement<Props> {
    showPreview = true;
    const preview = UserPreview({ user: user as User, children: user.name });
    showPreview = false;

    const content = find(preview, (props) => 'onMenuOpenChange' in props);
    assert.ok(content);

    const button = find(
        render({
            ...content,
            props: {
                ...content.props,
                ...(onChange && { onChange }),
                data: {
                    isFollowing,
                    followsYou: false,
                    isMuting: false,
                    isBlocking: false,
                    isUnavailable: false,
                    user: { ...user, followers_count: 0 } as unknown as Profile,
                },
            },
        }),
        (props) => props['data-test'] === 'user-preview-follow-button',
    );
    assert.ok(button);

    return button;
}

function followFromProfile(user: SuggestedUser): void {
    const profileButton = find(
        FollowButton({ user, isFollowing: false }),
        (props) => props['data-test'] === 'follow-button',
    );
    assert.ok(profileButton);

    (profileButton.props.onSuccess as () => void)();
}

test('a suggestion who follows you offers to follow back, at a width that fits it', () => {
    const user = { ...suggestion('freek'), follows_you: true };
    const button = find(
        SuggestedUserRow({ user }),
        (props) => props['data-test'] === 'suggested-follow-button',
    );

    assert.equal(suggestionLabel(user), 'Follow back @freek');
    assert.match(String(button?.props.className), /\bmin-w-26\b/);
    assert.equal(suggestionLabel(suggestion('jess')), 'Follow @jess');
});

test('a follow from their profile shows in who to follow', () => {
    const user = suggestion('taylor');

    followFromProfile(user);

    assert.equal(suggestionLabel(user), 'Unfollow @taylor');
});

test('blocking someone you follow shows them as not followed in who to follow', () => {
    const user = suggestion('blocked');
    followFromProfile(user);

    blockFromSafetyMenu(user);

    assert.equal(suggestionLabel(user), 'Follow @blocked');
});

test('blocking someone from their preview or profile takes them out of who to follow', () => {
    const user = suggestion('unwanted');
    assert.ok(isSuggested(user));

    blockFromSafetyMenu(user);

    assert.ok(!isSuggested(user));
});

test('a follow refused because they blocked you says so and takes them out of who to follow', () => {
    const user = suggestion('blocker');
    const button = find(
        SuggestedUserRow({ user }),
        (props) => props['data-test'] === 'suggested-follow-button',
    );
    assert.ok(button);
    const follow = find(
        render(button),
        (props) => props['data-test'] === 'suggested-follow-button',
    );
    assert.ok(follow);

    const showsErrorPage = (
        follow.props.onHttpException as (response: {
            status: number;
        }) => boolean
    )({ status: 403 });

    assert.equal(showsErrorPage, false);
    assert.equal(toasts.at(-1), "You can't follow @blocker.");
    assert.ok(!isSuggested(user));
});

test('a follow from their preview refused because they blocked you takes them out of who to follow', () => {
    const user = suggestion('previewblocker');

    (previewFollowButton(user).props.onClick as () => void)();
    const showsErrorPage = visits.at(-1)?.onHttpException?.({ status: 403 });

    assert.equal(showsErrorPage, false);
    assert.equal(toasts.at(-1), "You can't follow @previewblocker.");
    assert.ok(!isSuggested(user));
});

test('a follow from their preview shows in who to follow', () => {
    const user = suggestion('nuno');

    (previewFollowButton(user).props.onClick as () => void)();
    visits.at(-1)?.onSuccess?.();

    assert.equal(suggestionLabel(user), 'Unfollow @nuno');
});

test('blocking someone you follow from chat shows them as not followed in who to follow', async () => {
    const user = suggestion('chatter');
    followFromProfile(user);

    const block = find(
        ConversationActions({
            target: { kind: 'direct', partner: user },
            title: '@chatter',
            user: suggestion('viewer'),
            isArchived: false,
        }),
        (props) => props['data-test'] === 'confirm-block-button',
    );
    assert.ok(block);
    (block.props.onClick as () => void)();
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(suggestionLabel(user), 'Follow @chatter');
    assert.ok(!isSuggested(user));
});

for (const failure of ['onError', 'onNetworkError', 'onCancel'] as const) {
    test(`a follow from their preview that ends in ${failure} leaves who to follow unchanged`, () => {
        const user = suggestion(`jess-${failure}`);

        (previewFollowButton(user).props.onClick as () => void)();
        visits.at(-1)?.[failure]?.();

        assert.equal(suggestionLabel(user), `Follow @jess-${failure}`);
    });
}

test('a cached preview offers to unfollow someone followed elsewhere', () => {
    const user = suggestion('cached');
    followFromProfile(user);

    const button = previewFollowButton(user, false);
    (button.props.onClick as () => void)();

    assert.equal(button.props['aria-label'], 'Unfollow @cached');
    assert.equal(visits.at(-1)?.method, 'delete');
});

test('unfollowing through a cached preview keeps its follower count', () => {
    const user = suggestion('counted');
    followFromProfile(user);
    const changes: { user: Profile }[] = [];

    (
        previewFollowButton(user, false, (data) => changes.push(data)).props
            .onClick as () => void
    )();
    visits.at(-1)?.onSuccess?.();

    assert.equal(changes.at(-1)?.user.followers_count, 0);
});

test('a preview unfollow that fails after a block keeps who to follow unfollowed', () => {
    const user = suggestion('raced');
    followFromProfile(user);

    (previewFollowButton(user, true).props.onClick as () => void)();
    const unfollow = visits.at(-1);

    const block = find(
        UserSafetyMenu({ user, isMuting: false, isBlocking: false }),
        (props) => props.children === 'Block',
    );
    assert.ok(block);
    (block.props.onClick as () => void)();
    visits.at(-1)?.onSuccess?.();
    unfollow?.onError?.();

    assert.equal(suggestionLabel(user), 'Follow @raced');
});

function suggestionFollowLink(user: SuggestedUser): Props {
    const button = find(
        SuggestedUserRow({ user }),
        (props) => props['data-test'] === 'suggested-follow-button',
    );
    assert.ok(button);

    const link = find(render(button), (props) => 'aria-label' in props);
    assert.ok(link);

    return link.props;
}

test('following several suggestions in a row sends every follow without cancelling the others', () => {
    const first = suggestion('pauline');
    const second = suggestion('brent');

    const firstFollow = suggestionFollowLink(first);
    (firstFollow.onStart as () => void)();
    const secondFollow = suggestionFollowLink(second);

    assert.equal(firstFollow.async, true);
    assert.equal(secondFollow.async, true);
    assert.equal((secondFollow.onBefore as () => boolean)(), true);

    (secondFollow.onStart as () => void)();
    for (const follow of [secondFollow, firstFollow]) {
        (follow.onSuccess as () => void)();
        (follow.onFinish as () => void)();
    }

    assert.equal(suggestionLabel(first), 'Unfollow @pauline');
    assert.equal(suggestionLabel(second), 'Unfollow @brent');
});

test('a second click on a follow still on its way sends nothing', () => {
    const user = suggestion('matthieu');

    (suggestionFollowLink(user).onStart as () => void)();

    const pending = suggestionFollowLink(user);
    assert.equal(pending.disabled, true);
    assert.equal((pending.onBefore as () => boolean)(), false);

    (pending.onFinish as () => void)();

    const finished = suggestionFollowLink(user);
    assert.equal(finished.disabled, false);
    assert.equal((finished.onBefore as () => boolean)(), true);
});

for (const lane of ['latest', 'trending']) {
    test(`following a suggestion in ${lane} preserves the loaded log`, () => {
        component = 'log/show';
        log = lane;

        const follow = suggestionFollowLink(suggestion(`suggested-${lane}`));

        assert.deepEqual(follow.only, ['auth.user', 'liveFollowing']);
        assert.equal(follow.async, true);
        assert.equal(follow.preserveState, true);
        assert.equal(follow.preserveScroll, true);
    });

    test(`following from a preview in ${lane} is a silent partial visit`, () => {
        component = 'log/show';
        log = lane;

        (
            previewFollowButton(suggestion(`preview-${lane}`)).props
                .onClick as () => void
        )();

        assert.deepEqual(visits.at(-1)?.only, ['auth.user', 'liveFollowing']);
        assert.equal(visits.at(-1)?.async, true);
        assert.equal(visits.at(-1)?.preserveState, true);
        assert.equal(visits.at(-1)?.preserveScroll, true);
    });
}

test('following from either log control replaces Following results and its empty state', () => {
    component = 'log/show';
    log = 'following';
    const expected = [
        'auth.user',
        'liveFollowing',
        'commits',
        'suggestions',
        'newCommitsCount',
    ];

    const follow = suggestionFollowLink(suggestion('following-suggestion'));
    (
        previewFollowButton(suggestion('following-preview')).props
            .onClick as () => void
    )();

    for (const request of [follow, visits.at(-1)]) {
        assert.deepEqual(request?.only, expected);
        assert.deepEqual(request?.headers, {
            'X-Follow-Request': 'true',
            'X-Inertia-Reset': 'commits',
        });
    }
});

test('both Following controls carry the oldest loaded row through the follow redirect', () => {
    component = 'log/show';
    log = 'following';
    loadedCommits = { data: [{ id: 'newest' }, { id: 'oldest-loaded' }] };

    const follow = suggestionFollowLink(suggestion('range-suggestion'));
    (
        previewFollowButton(suggestion('range-preview')).props
            .onClick as () => void
    )();

    for (const request of [follow, visits.at(-1)]) {
        assert.deepEqual(request?.headers, {
            'X-Follow-Request': 'true',
            'X-Inertia-Reset': 'commits',
            'X-Log-Through': 'oldest-loaded',
        });
    }
});

function shownSuggestions(): (string | number)[] {
    return (suggestedUsers ?? []).slice(0, 3).map((user) => user.id);
}

function suggestionRow(user: SuggestedUser): Props {
    const row = find(
        WhoToFollow(),
        (props) => (props.user as SuggestedUser | undefined)?.id === user.id,
    );
    assert.ok(row);

    return row.props;
}

function dismissSuggestion(user: SuggestedUser): boolean {
    return (
        suggestionRow(user).onDismiss as (onFailed: () => void) => boolean
    )(() => {});
}

function hasElementNamed(node: ReactNode, name: string): boolean {
    if (Array.isArray(node)) {
        return node.some((child) => hasElementNamed(child, name));
    }

    if (!React.isValidElement<Props>(node)) {
        return false;
    }

    return (
        (node.type as { name?: string }).name === name ||
        hasElementNamed(node.props.children, name)
    );
}

function suggestions(...usernames: string[]): SuggestedUser[] {
    return usernames.map(suggestion);
}

function finishTopUp(topUp: VisitOptions): void {
    topUp.onBeforeUpdate?.();
    topUp.onSuccess?.();
    topUp.onFinish?.();
}

test('a failed dismissal takes its slot back from whoever holds it, without bringing back a saved one', () => {
    const [ada, bob, cleo, dina, eve, finn] = suggestions(
        'ada',
        'bob',
        'cleo',
        'dina',
        'eve',
        'finn',
    );
    suggestedUsers = [ada, bob, cleo, dina, eve, finn];

    assert.equal(dismissSuggestion(ada), true);
    const failed = visits.at(-1)!;
    assert.equal(dismissSuggestion(dina), true);
    const saved = visits.at(-1)!;
    assert.deepEqual(shownSuggestions(), ['eve', 'bob', 'cleo']);

    saved.onSuccess?.();
    saved.onFinish?.();
    failed.onFinish?.();

    assert.deepEqual(
        suggestedUsers.map((user) => user.id),
        ['ada', 'bob', 'cleo', 'eve', 'finn'],
    );
});

test('a dismissal that fails after a refresh leaves the new suggestions alone', () => {
    const [gus, hana, ivo, jay] = suggestions('gus', 'hana', 'ivo', 'jay');
    suggestedUsers = [gus, hana, ivo, jay];

    dismissSuggestion(gus);
    const failed = visits.at(-1)!;
    document.cookie = 'who_to_follow_round=1';
    const refreshed = suggestions('kai', 'lea', 'max');
    suggestedUsers = refreshed;
    failed.onFinish?.();
    document.cookie = '';

    assert.deepEqual(suggestedUsers, refreshed);
});

test('nobody is left only once a top-up after every dismissal finds nobody, in both blocks', () => {
    const [nia] = suggestions('nia');
    suggestedUsers = [nia];

    assert.equal(dismissSuggestion(nia), false);
    const dismissal = visits.at(-1)!;
    timers.splice(0).forEach((timer) => timer());
    assert.deepEqual(suggestedUsers, []);
    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), false);

    dismissal.onSuccess?.();
    dismissal.onFinish?.();
    assert.deepEqual(reloads.at(-1)?.only, ['suggestedUsers']);
    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), false);

    finishTopUp(reloads.at(-1)!);
    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), true);
    assert.equal(
        hasElementNamed(WhoToFollowInLog(), 'WhoToFollowEmptied'),
        true,
    );
});

test('a top-up cancelled by visiting another page is sent again once it shows', () => {
    const [oli] = suggestions('oli');
    suggestedUsers = [oli];

    dismissSuggestion(oli);
    visits.at(-1)?.onSuccess?.();
    visits.at(-1)?.onFinish?.();
    const cancelled = reloads.at(-1)!;
    cancelled.onFinish?.();
    timers.splice(0).forEach((timer) => timer());

    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), false);

    navigateListeners.slice().forEach((listener) => listener());

    assert.notEqual(reloads.at(-1), cancelled);
    assert.deepEqual(reloads.at(-1)?.only, ['suggestedUsers']);
    assert.equal(navigateListeners.length, 0);
});

test('a top-up that lands after someone else was dismissed takes them back out and tops up again', () => {
    const [pia, quin, rae, sam, tom, uma] = suggestions(
        'pia',
        'quin',
        'rae',
        'sam',
        'tom',
        'uma',
    );
    suggestedUsers = [pia, quin, rae, sam, tom];

    dismissSuggestion(pia);
    visits.at(-1)?.onSuccess?.();
    visits.at(-1)?.onFinish?.();
    const topUp = reloads.at(-1)!;

    dismissSuggestion(quin);
    visits.at(-1)?.onSuccess?.();
    visits.at(-1)?.onFinish?.();
    assert.equal(reloads.at(-1), topUp);

    suggestedUsers = [sam, quin, rae, tom, uma];
    finishTopUp(topUp);

    assert.deepEqual(
        suggestedUsers.map((user) => user.id),
        ['sam', 'tom', 'rae', 'uma'],
    );
    assert.notEqual(reloads.at(-1), topUp);
});

function followSuggestion(user: SuggestedUser): void {
    const button = find(
        SuggestedUserRow(
            suggestionRow(user) as Parameters<typeof SuggestedUserRow>[0],
        ),
        (props) => props['data-test'] === 'suggested-follow-button',
    );
    assert.ok(button);

    const link = find(render(button), (props) => 'aria-label' in props);
    assert.ok(link);

    (link.props.onSuccess as () => void)();
}

test('following a suggestion hands its slot to the next spare a moment later and tops up the spares', () => {
    const [vic, wren, xan, yara, zoe] = suggestions(
        'vic',
        'wren',
        'xan',
        'yara',
        'zoe',
    );
    suggestedUsers = [vic, wren, xan, yara];
    timers.splice(0);
    const visitCount = visits.length;

    followSuggestion(wren);

    assert.deepEqual(shownSuggestions(), ['vic', 'wren', 'xan']);

    timers.splice(0).forEach((timer) => timer());

    assert.deepEqual(shownSuggestions(), ['vic', 'yara', 'xan']);
    assert.equal(visits.length, visitCount);
    assert.deepEqual(reloads.at(-1)?.only, ['suggestedUsers']);

    suggestedUsers = [vic, yara, xan, wren, zoe];
    finishTopUp(reloads.at(-1)!);

    assert.deepEqual(
        suggestedUsers.map((user) => user.id),
        ['vic', 'yara', 'xan', 'zoe'],
    );
});

test('following the last suggestion says nobody is left once a top-up finds nobody', () => {
    const [ugo] = suggestions('ugo');
    suggestedUsers = [ugo];
    timers.splice(0);

    followSuggestion(ugo);
    timers.splice(0).forEach((timer) => timer());
    timers.splice(0).forEach((timer) => timer());

    assert.deepEqual(suggestedUsers, []);
    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), false);

    finishTopUp(reloads.at(-1)!);

    assert.equal(hasElementNamed(WhoToFollow(), 'WhoToFollowEmptied'), true);
});

test('someone followed and then unfollowed can be suggested again by a later top-up', () => {
    const [abe, bea, cal, dee, eli] = suggestions('abe', 'bea', 'cal', 'dee', 'eli');
    suggestedUsers = [abe, bea, cal, dee, eli];
    timers.splice(0);

    followSuggestion(bea);
    timers.splice(0).forEach((timer) => timer());
    finishTopUp(reloads.at(-1)!);

    assert.deepEqual(shownSuggestions(), ['abe', 'dee', 'cal']);

    dismissSuggestion(abe);
    visits.at(-1)?.onSuccess?.();
    visits.at(-1)?.onFinish?.();
    suggestedUsers = [eli, dee, cal, bea];
    finishTopUp(reloads.at(-1)!);

    assert.deepEqual(
        suggestedUsers.map((user) => user.id),
        ['eli', 'dee', 'cal', 'bea'],
    );
});

test('a follow whose row is gone by the time it makes way leaves a page without suggestions alone', () => {
    const [fay, gil, hal, ida] = suggestions('fay', 'gil', 'hal', 'ida');
    suggestedUsers = [fay, gil, hal, ida];
    timers.splice(0);

    followSuggestion(gil);
    suggestedUsers = undefined;
    timers.splice(0).forEach((timer) => timer());

    assert.equal(suggestedUsers, undefined);
    assert.deepEqual(reloads.at(-1)?.only, ['suggestedUsers']);
});
