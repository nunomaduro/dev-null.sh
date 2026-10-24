/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import { afterAll, afterEach, test, vi } from 'vite-plus/test';

const updates = vi.fn();
const cleanups: (() => void)[] = [];

vi.doMock('react', () => ({
    ...React,
    useState: (getInitial: () => number) => [getInitial(), updates],
    useEffect: (effect: () => (() => void) | undefined) => {
        const cleanup = effect();
        if (cleanup) cleanups.push(cleanup);
    },
}));

const { ProductHuntBanner } = await import(
    '../../resources/js/components/product-hunt-banner'
);

afterEach(() => {
    cleanups.splice(0).forEach((cleanup) => cleanup());
    vi.useRealTimers();
    updates.mockClear();
});
afterAll(() => vi.doUnmock('react'));

test('expired launch does not leave a one-second timer running', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-25T12:00:00Z'));

    assert.equal(ProductHuntBanner(), null);
    assert.equal(vi.getTimerCount(), 0);
});

test('before launch, schedules only the launch start', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-19T12:00:00Z'));

    assert.equal(ProductHuntBanner(), null);
    assert.equal(vi.getTimerCount(), 1);

    vi.advanceTimersByTime(1_000);
    assert.equal(updates.mock.calls.length, 0);

    vi.advanceTimersToNextTimer();
    assert.equal(updates.mock.calls.length, 1);
});

test('during launch, schedules only the launch end', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-20T12:00:00Z'));

    assert.notEqual(ProductHuntBanner(), null);
    assert.equal(vi.getTimerCount(), 1);

    vi.advanceTimersByTime(1_000);
    assert.equal(updates.mock.calls.length, 0);

    vi.advanceTimersToNextTimer();
    assert.equal(updates.mock.calls.length, 1);
});
