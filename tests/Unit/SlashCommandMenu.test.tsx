/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { beforeEach, test, vi } from 'vite-plus/test';

let state: unknown[] = [];
let cursor = 0;

vi.doMock('react', () => ({
    ...React,
    useEffect: () => {},
    useMemo: (factory: () => unknown) => factory(),
    useId: () => `menu-${cursor++}`,
    useSyncExternalStore: (_subscribe: unknown, snapshot: () => unknown) =>
        snapshot(),
    useRef: (initial: unknown) => {
        const index = cursor++;
        if (!(index in state)) {
            state[index] = { current: initial };
        }
        return state[index];
    },
    useState: (initial: unknown) => {
        const index = cursor++;
        if (!(index in state)) {
            state[index] = typeof initial === 'function' ? initial() : initial;
        }
        return [
            state[index],
            (value: unknown) => {
                state[index] =
                    typeof value === 'function' ? value(state[index]) : value;
            },
        ];
    },
}));
vi.doMock('../../resources/js/lib/caret-position', () => ({
    caretMenuPosition: () => ({ left: 0, top: 0 }),
}));

const { MentionTextarea } =
    await import('../../resources/js/components/mention-textarea');

type Element = ReactElement<Record<string, unknown>>;

const surface = (overrides: Record<string, unknown> = {}) => ({
    surface: 'commit',
    hasPoll: false,
    imageCount: 0,
    isScheduling: false,
    processing: false,
    ...overrides,
});

let value = '';
const onValueChange = vi.fn((next: string) => {
    value = next;
});
const onImage = vi.fn();
const onPoll = vi.fn();

const renderer = MentionTextarea as unknown as (
    props: Record<string, unknown>,
) => ReactElement;

function render(overrides: Record<string, unknown> = {}) {
    cursor = 0;

    return renderer({
        id: 'body',
        value,
        onValueChange,
        commandContext: surface(),
        onImage,
        onPoll,
        maxLength: 4000,
        ...overrides,
    });
}

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

function field(tree: ReactElement): Element {
    return elements(tree).find((element) => element.type === 'textarea')!;
}

function type(next: string, overrides: Record<string, unknown> = {}) {
    const tree = render(overrides);
    (field(tree).props.onChange as (event: unknown) => void)({
        currentTarget: {
            value: next,
            selectionStart: next.length,
            selectionEnd: next.length,
        },
    });

    return render(overrides);
}

function press(tree: ReactElement, key: string) {
    const event = {
        key,
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        shiftKey: false,
        nativeEvent: { isComposing: false },
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
    };

    (field(tree).props.onKeyDown as (event: unknown) => void)(event);

    return event;
}

function row(tree: ReactElement, command: string): Element | undefined {
    return elements(tree).find(
        (element) =>
            element.props['data-test'] === 'slash-command' &&
            elements(element).some(
                (child) => child.props.children === `/${command}`,
            ),
    );
}

function menu(tree: ReactElement, testId: string): Element | undefined {
    return elements(tree).find((element) => element.props.testId === testId);
}

function mentionRows(tree: ReactElement): Element[] {
    return elements(tree).filter(
        (element) => element.props['data-test'] === 'mention-suggestion',
    );
}

function emojiRows(tree: ReactElement): Element[] {
    return elements(tree).filter(
        (element) => element.props['data-test'] === 'emoji-suggestion',
    );
}

function searching(tree: ReactElement): boolean {
    return elements(tree).some((element) =>
        React.Children.toArray(element.props.children as ReactNode).includes(
            'Searching…',
        ),
    );
}

const alice = { id: 'alice', username: 'alice', name: 'Alice', lane: 1 };

beforeEach(() => {
    vi.clearAllMocks();
    state = [];
    value = '';
    vi.stubGlobal('requestAnimationFrame', vi.fn());
});

test('typing / opens the command menu with every available command', () => {
    const tree = type('/');

    assert.ok(row(tree, 'image'));
    assert.ok(row(tree, 'poll'));
    assert.ok(row(tree, 'code'));
});

test('filters the menu by prefix, alias included', () => {
    assert.ok(row(type('/po'), 'poll'));
    assert.equal(row(type('/po'), 'image'), undefined);
    assert.ok(row(type('/img'), 'image'));
    assert.equal(row(type('/x'), 'image'), undefined);
});

test('selecting poll activates the poll editor and removes the command text', () => {
    const tree = type('Ask /poll');

    (row(tree, 'poll')!.props.onClick as () => void)();

    assert.equal(onValueChange.mock.lastCall?.[0], 'Ask');
    assert.equal(onPoll.mock.calls.length, 1);
    assert.equal(row(render(), 'poll'), undefined);
});

test('an exact command followed by space runs without picking', () => {
    const tree = type('see /image');
    const event = press(tree, ' ');

    assert.equal(event.preventDefault.mock.calls.length, 1);
    assert.equal(onImage.mock.calls.length, 1);
    assert.equal(onValueChange.mock.lastCall?.[0], 'see');
});

test('disabled commands stay listed with their reason and never run', () => {
    const overrides = { commandContext: surface({ hasPoll: true }) };
    const tree = type('/image', overrides);

    const image = row(tree, 'image')!;
    assert.equal(image.props['aria-disabled'], true);
    assert.ok(
        elements(image).some(
            (element) => element.props.children === 'Remove the poll first',
        ),
    );

    const event = press(tree, ' ');

    assert.equal(event.preventDefault.mock.calls.length, 0);
    assert.equal(onImage.mock.calls.length, 0);
    assert.ok(row(render(overrides), 'image'));
});

test('escape keeps the text, and the menu stays dismissed until the token is re-formed', () => {
    const tree = type('/po');
    press(tree, 'Escape');

    assert.equal(row(render(), 'poll'), undefined);
    assert.equal(row(type('/pol'), 'poll'), undefined);
    type('');
    assert.ok(row(type('/po'), 'poll'));
});

test('code inserts a fenced block where the command was typed', () => {
    const tree = type('see /code');
    press(tree, ' ');

    assert.equal(onValueChange.mock.lastCall?.[0], 'see \n```\n\n```');
});

test('code at the start of the body inserts without padding', () => {
    const tree = type('/code');
    press(tree, ' ');

    assert.equal(onValueChange.mock.lastCall?.[0], '```\n\n```');
});

test('code does not insert when the block would exceed the character limit', () => {
    const prefix = `${'a'.repeat(4000 - '/code'.length - 1)} `;
    value = prefix;
    const onKeyDown = vi.fn();
    const tree = type(`${prefix}/code`, { onKeyDown });
    const event = press(tree, ' ');

    assert.equal(event.preventDefault.mock.calls.length, 0);
    assert.equal(onValueChange.mock.calls.length, 1);
    assert.equal(onValueChange.mock.lastCall?.[0], `${prefix}/code`);
    assert.equal(onKeyDown.mock.calls.length, 1);
});

test('enter runs the active command when the block fits', () => {
    const tree = type('see /code');
    const event = press(tree, 'Enter');

    assert.equal(event.preventDefault.mock.calls.length, 1);
    assert.equal(onValueChange.mock.lastCall?.[0], 'see \n```\n\n```');
});

test('enter at the character limit is left to the caller', () => {
    const prefix = `${'a'.repeat(4000 - '/code'.length - 1)} `;
    value = prefix;
    const onKeyDown = vi.fn();
    const tree = type(`${prefix}/code`, { onKeyDown });
    const event = press(tree, 'Enter');

    assert.equal(event.preventDefault.mock.calls.length, 0);
    assert.equal(onValueChange.mock.calls.length, 1);
    assert.equal(onKeyDown.mock.calls.length, 1);
});

test('the two menus do not open for each other', () => {
    assert.equal(menu(type('/'), 'mention-suggestions'), undefined);

    const mention = type('@ali');

    assert.ok(menu(mention, 'mention-suggestions'));
    assert.equal(row(mention, 'image'), undefined);
});

test('typing @ opens the mention menu in its searching state', () => {
    const tree = type('@ali');

    assert.ok(menu(tree, 'mention-suggestions'));
    assert.equal(mentionRows(tree).length, 0);
    assert.ok(searching(tree));
});

test('mention results for a stale query are not shown', () => {
    state[5] = {
        query: 'bob',
        users: [{ id: 'bob', username: 'bob', name: 'Bob', lane: 2 }],
    };
    const tree = type('@ali');

    assert.ok(menu(tree, 'mention-suggestions'));
    assert.equal(mentionRows(tree).length, 0);
    assert.ok(searching(tree));
});

test('selecting a mention inserts @username and closes the menu', () => {
    state[5] = { query: 'ali', users: [alice] };
    const tree = type('@ali');
    const suggestion = mentionRows(tree)[0]!;

    assert.equal(suggestion.props.id, 'menu-0-mention-alice');

    (field(tree).props.ref as (element: unknown) => void)({
        focus: vi.fn(),
        setSelectionRange: vi.fn(),
    });

    (suggestion.props.onClick as () => void)();

    assert.equal(onValueChange.mock.lastCall?.[0], '@alice ');

    const closed = render();

    assert.equal(mentionRows(closed).length, 0);
    assert.equal(menu(closed, 'mention-suggestions'), undefined);
});

test('poll is absent in replies and when no poll callback is given', () => {
    assert.equal(
        row(
            type('/', { commandContext: surface({ surface: 'reply' }) }),
            'poll',
        ),
        undefined,
    );
    assert.equal(row(type('/', { onPoll: undefined }), 'poll'), undefined);
    assert.ok(row(type('/', { onPoll: undefined }), 'code'));
});

test('typing a shortcode opens the emoji menu', () => {
    const tree = type('ship it :sm');

    assert.ok(menu(tree, 'emoji-suggestions'));
    assert.ok(emojiRows(tree).length > 0);
    assert.equal(row(tree, 'image'), undefined);
});

test('selecting an emoji inserts it and closes the menu', () => {
    const tree = type('ship it :sm');
    const suggestion = emojiRows(tree)[0]!;

    (field(tree).props.ref as (element: unknown) => void)({
        focus: vi.fn(),
        setSelectionRange: vi.fn(),
    });

    (suggestion.props.onClick as () => void)();

    const inserted = onValueChange.mock.lastCall?.[0] ?? '';

    assert.ok(inserted.startsWith('ship it '));
    assert.ok(!inserted.includes(':sm'));

    const closed = render();

    assert.equal(emojiRows(closed).length, 0);
    assert.equal(menu(closed, 'emoji-suggestions'), undefined);
});

test('the emoji menu stays quiet inside code spans', () => {
    const tree = type('`code :sm`');

    assert.equal(menu(tree, 'emoji-suggestions'), undefined);
});

test('submitting disables every command', () => {
    const tree = type('/', {
        commandContext: surface({ processing: true }),
    });

    const code = row(tree, 'code')!;
    assert.equal(code.props['aria-disabled'], true);
    assert.ok(
        elements(code).some(
            (element) => element.props.children === 'Insert a code block',
        ),
    );
});
