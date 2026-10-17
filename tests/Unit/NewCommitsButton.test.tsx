/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { beforeEach, test, vi } from 'vite-plus/test';
import type { Commit, CursorPaginated } from '../../resources/js/types';

type LocationListener = (event: {
    detail: { versionChange: boolean };
}) => void;

const locationListeners: LocationListener[] = [];
const stopPolling = vi.fn();
const router = {
    on: vi.fn((name: string, listener: LocationListener) => {
        if (name === 'location') {
            locationListeners.push(listener);
        }

        return () => {};
    }),
    reload: vi.fn(),
    flushAll: vi.fn(),
    replaceProp: vi.fn(),
};
const assign = vi.fn();

// Effects run as the page renders, so it subscribes to Inertia's events right away.
vi.doMock('react', () => ({
    ...React,
    useEffect: (effect: () => void) => effect(),
    useRef: (initial: unknown) => ({ current: initial }),
    useState: (initial: unknown) => [
        typeof initial === 'function' ? (initial as () => unknown)() : initial,
        vi.fn(),
    ],
}));
vi.doMock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router,
    usePage: () => ({ flash: {}, props: { auth: { user: null } } }),
    usePoll: () => ({ stop: stopPolling }),
}));
vi.doMock('../../resources/js/hooks/use-last-visit', () => ({
    useLastVisit: () => undefined,
}));
vi.doMock('../../resources/js/components/commit-composer-dialog', () => ({
    useCommitDialogClosedCount: () => 0,
}));
vi.doMock('../../resources/js/lib/storage', () => ({
    readStorage: () => '',
    writeStorage: vi.fn(),
}));

const { default: Show } = await import('../../resources/js/pages/log/show');

// Only the page's click handler needs a browser window, and Inertia would read its user agent if it existed earlier.
vi.stubGlobal('window', {
    location: { pathname: '/log/latest', assign },
    scrollTo: vi.fn(),
});

function elements(node: ReactNode): ReactElement<Record<string, unknown>>[] {
    if (!React.isValidElement(node)) {
        return [];
    }

    const element = node as ReactElement<Record<string, unknown>>;

    return [
        element,
        ...React.Children.toArray(element.props.children as ReactNode).flatMap(
            elements,
        ),
    ];
}

/**
 * Renders the Latest log with new commits waiting, as after a poll.
 */
function renderLog() {
    const page = Show({
        log: 'latest',
        commits: {
            data: [{ id: 'newest' } as Commit],
        } as CursorPaginated<Commit>,
        isRanked: false,
        newestCommitId: 'newest',
        latestCommits: [],
        newCommitsCount: 3,
        suggestions: [],
    });
    const button = elements(page).find(
        (element) =>
            element.type === 'button' && typeof element.props.onClick === 'function',
    )!;

    return {
        navigate: (versionChange: boolean) =>
            locationListeners.at(-1)!({ detail: { versionChange } }),
        showNewCommits: () => (button.props.onClick as () => void)(),
    };
}

beforeEach(() => {
    locationListeners.length = 0;
    vi.clearAllMocks();
});

test('loads the new commits in place while the assets are current', () => {
    renderLog().showNewCommits();

    assert.equal(router.reload.mock.calls.length, 1);
    assert.deepEqual(
        (router.reload.mock.calls[0][0] as { only: string[] }).only,
        ['commits', 'newCommitsCount'],
    );
    assert.equal(assign.mock.calls.length, 0);
});

test('loads the log in full once a deploy changed the asset version', () => {
    const log = renderLog();

    log.navigate(true);
    log.showNewCommits();

    assert.equal(stopPolling.mock.calls.length, 1);
    assert.deepEqual(assign.mock.calls, [['/log/latest']]);
    assert.equal(router.reload.mock.calls.length, 0);
});

test('keeps loading in place after a visit without a new version', () => {
    const log = renderLog();

    log.navigate(false);
    log.showNewCommits();

    assert.equal(stopPolling.mock.calls.length, 0);
    assert.equal(router.reload.mock.calls.length, 1);
    assert.equal(assign.mock.calls.length, 0);
});
