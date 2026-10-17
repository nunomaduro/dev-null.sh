/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { beforeEach, test, vi } from 'vite-plus/test';

let state: unknown[] = [];
let cursor = 0;

vi.doMock('react', () => ({
    ...React,
    useEffect: (effect: () => void) => effect(),
    useMemo: (factory: () => unknown) => factory(),
    useId: () => `preview-${cursor++}`,
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
                    typeof value === 'function'
                        ? (value as (current: unknown) => unknown)(state[index])
                        : value;
            },
        ];
    },
}));

const put = vi.fn();
const destroy = vi.fn();

vi.doMock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router: { put, delete: destroy },
}));

const { GiphyKeySetting } = await import(
    '../../resources/js/components/giphy-key-setting'
);

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

function render(giphyConnected: boolean): Element[] {
    cursor = 0;

    return elements(GiphyKeySetting({ giphyConnected }));
}

function field(fields: Element[], testId: string): Element {
    const found = fields.find(
        (element) => element.props['data-test'] === testId,
    );

    assert.ok(found, `no element with data-test="${testId}"`);

    return found;
}

function stubFetch(result: number | 'network'): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => {
            if (result === 'network') {
                throw new TypeError('fetch failed');
            }

            return new Response('{}', { status: result });
        }),
    );
}

function typeKey(fields: Element[], value: string): Element[] {
    (field(fields, 'giphy-key-input').props.onChange as (event: {
        target: { value: string };
    }) => void)({
        target: { value },
    });

    return render(false);
}

beforeEach(() => {
    state = [];
    cursor = 0;
    vi.clearAllMocks();
    vi.unstubAllGlobals();
});

test('saves a valid key after giphy accepts it', async () => {
    stubFetch(200);

    let fields = typeKey(render(false), '  key-123  ');
    await (field(fields, 'giphy-connect-button').props.onClick as () => Promise<void>)();

    assert.equal(put.mock.calls.length, 1);
    assert.equal(put.mock.calls[0]?.[0], '/settings/giphy');
    assert.deepEqual(put.mock.calls[0]?.[1], { key: 'key-123' });

    const options = put.mock.calls[0]?.[2] as { onSuccess: () => void };
    options.onSuccess();

    fields = render(true);
    assert.ok(field(fields, 'giphy-connected'));
});

test('saves a rate-limited key because the key itself is real', async () => {
    stubFetch(429);

    const fields = typeKey(render(false), 'key-123');
    await (field(fields, 'giphy-connect-button').props.onClick as () => Promise<void>)();

    assert.equal(put.mock.calls.length, 1);
});

test('rejects a refused key without saving', async () => {
    stubFetch(401);

    const fields = typeKey(render(false), 'key-123');
    await (field(fields, 'giphy-connect-button').props.onClick as () => Promise<void>)();

    assert.equal(put.mock.calls.length, 0);
    assert.equal(
        field(render(false), 'giphy-key-error').props.children,
        'That key was rejected by GIPHY.',
    );
});

test('does not save when giphy cannot be reached', async () => {
    stubFetch('network');

    const fields = typeKey(render(false), 'key-123');
    await (field(fields, 'giphy-connect-button').props.onClick as () => Promise<void>)();

    assert.equal(put.mock.calls.length, 0);
    assert.equal(
        field(render(false), 'giphy-key-error').props.children,
        "Couldn't reach GIPHY to verify the key.",
    );
});

test('disables connect until a key is typed', () => {
    const fields = render(false);

    assert.equal(field(fields, 'giphy-connect-button').props.disabled, true);
});

test('surfaces server validation errors', async () => {
    stubFetch(200);

    const fields = typeKey(render(false), 'key-123');
    await (field(fields, 'giphy-connect-button').props.onClick as () => Promise<void>)();

    const options = put.mock.calls[0]?.[2] as {
        onError: (errors: Record<string, string>) => void;
    };
    options.onError({ key: 'The key field is required.' });

    assert.equal(
        field(render(false), 'giphy-key-error').props.children,
        'The key field is required.',
    );
});

test('removes a connected key', () => {
    const fields = render(true);

    (field(fields, 'giphy-remove-button').props.onClick as () => void)();

    assert.equal(destroy.mock.calls.length, 1);
    assert.equal(destroy.mock.calls[0]?.[0], '/settings/giphy');
});
