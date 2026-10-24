/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import { afterAll, afterEach, test, vi } from 'vite-plus/test';
import type { Log, User } from '../../resources/js/types';

let onKeyDown: ((event: KeyboardEvent) => void) | undefined;
let cleanup: (() => void) | undefined;
let activeCard: FakeCard | null = null;
let focusedCard: FakeCard | null = null;
const clicked: string[] = [];

type FakeCard = {
    querySelector: (selector: string) => {
        closest: (selector: string) => null;
        click: () => void;
    } | null;
};

function card(name: string): FakeCard {
    return {
        querySelector: (selector) =>
            selector === 'button[aria-keyshortcuts="r"]'
                ? { closest: () => null, click: () => clicked.push(name) }
                : null,
    };
}

vi.doMock('react', () => ({
    ...React,
    useEffect: (effect: () => (() => void) | undefined) => {
        cleanup = effect();
    },
}));
vi.doMock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));
vi.stubGlobal('HTMLElement', class {});
vi.stubGlobal('CSS', { escape: (key: string) => key });
vi.stubGlobal('document', {
    activeElement: {
        closest: (selector: string) =>
            selector === '[data-commit]' ? activeCard : null,
    },
    querySelector: (selector: string) =>
        selector === '[data-focused-commit]' ? focusedCard : null,
});
vi.stubGlobal('window', {
    addEventListener: (
        event: string,
        callback: (event: KeyboardEvent) => void,
    ) => {
        if (event === 'keydown') onKeyDown = callback;
    },
    removeEventListener: (event: string) => {
        if (event === 'keydown') onKeyDown = undefined;
    },
});

afterAll(() => vi.unstubAllGlobals());

const { useKeyboardShortcuts } = await import(
    '../../resources/js/hooks/use-keyboard-shortcuts'
);

function keydown(key: string): boolean {
    let prevented = false;
    onKeyDown?.({
        key,
        target: null,
        defaultPrevented: false,
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        preventDefault: () => {
            prevented = true;
        },
    } as KeyboardEvent);
    return prevented;
}

function mount(): void {
    useKeyboardShortcuts(
        { id: 'viewer' } as User,
        'latest' as Log,
        () => {},
        () => {},
        () => {},
    );
}

afterEach(() => {
    cleanup?.();
    cleanup = undefined;
    activeCard = null;
    focusedCard = null;
    clicked.length = 0;
});

test('reply shortcut uses the main commit when no commit is focused', () => {
    focusedCard = card('main');
    mount();

    assert.equal(keydown('r'), true);
    assert.deepEqual(clicked, ['main']);
});

test('reply shortcut prefers the commit containing keyboard focus', () => {
    focusedCard = card('main');
    activeCard = card('reply');
    mount();

    assert.equal(keydown('r'), true);
    assert.deepEqual(clicked, ['reply']);
});

test('without a focused commit or a commit detail page, reply shortcut does nothing', () => {
    mount();

    assert.equal(keydown('r'), false);
    assert.deepEqual(clicked, []);
});
