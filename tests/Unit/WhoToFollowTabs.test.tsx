/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import { test, vi } from 'vite-plus/test';

const setSelected = vi.fn();
const setShowsWhoToFollow = vi.fn();
const updates: (() => void)[] = [];

vi.doMock('react', () => ({
    ...React,
    useState: () => [true, setSelected],
    useRef: (value: boolean) => ({ current: value }),
}));
vi.doMock('../../resources/js/hooks/use-who-to-follow', () => ({
    useWhoToFollow: () => ({
        showsWhoToFollow: true,
        setShowsWhoToFollow,
    }),
}));
vi.doMock('../../resources/js/lib/utils', () => ({
    withViewTransition: (update: () => void) => {
        updates.push(update);
        return Promise.resolve();
    },
    cn: () => '',
}));

const { default: WhoToFollowTabs } =
    await import('../../resources/js/components/who-to-follow-tabs');

test('rapid selections keep the last request before a view transition updates state', async () => {
    const group = WhoToFollowTabs();

    group.props.onChange(false);
    group.props.onChange(true);
    group.props.onChange(true);

    assert.equal(updates.length, 2);
    for (const update of updates) {
        update();
    }
    await Promise.resolve();

    assert.deepEqual(setSelected.mock.calls, [[false], [true]]);
    assert.deepEqual(setShowsWhoToFollow.mock.calls, [[false], [true]]);
});
