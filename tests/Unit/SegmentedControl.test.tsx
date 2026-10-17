/// <reference types="node" />
import assert from 'node:assert/strict';
import { Monitor, Moon, Sun } from 'lucide-react';
import { Children } from 'react';
import type { KeyboardEvent, ReactElement } from 'react';
import { test, vi } from 'vite-plus/test';
import { SegmentedControl } from '../../resources/js/components/segmented-control';

type Radio = ReactElement<{
    tabIndex: number;
    'aria-checked': boolean;
    onClick: () => void;
    onKeyDown: (event: KeyboardEvent<HTMLButtonElement>) => void;
}>;

function render(value: boolean | null | 'missing' = true) {
    const onChange = vi.fn();
    const group = SegmentedControl({
        label: 'Suggestions',
        name: 'suggestions',
        options: [
            { value: true, label: 'Show', icon: Sun },
            { value: false, label: 'Hide', icon: Moon },
            { value: null, label: 'Auto', icon: Monitor },
        ],
        value,
        onChange,
    });
    const radios = Children.toArray(group.props.children) as Radio[];
    const buttons = radios.map(() => ({ focus: vi.fn() }));

    function press(index: number, key: string, modifiers = {}) {
        const preventDefault = vi.fn();
        radios[index].props.onKeyDown({
            key,
            altKey: false,
            ctrlKey: false,
            metaKey: false,
            ...modifiers,
            preventDefault,
            currentTarget: {
                parentElement: { querySelectorAll: () => buttons },
            },
        } as unknown as KeyboardEvent<HTMLButtonElement>);

        return preventDefault;
    }

    return { radios, buttons, onChange, press };
}

test('only the checked radio is a Tab stop, including a false value', () => {
    for (const value of [true, false]) {
        const { radios } = render(value);
        assert.deepEqual(
            radios.map((radio) => radio.props.tabIndex),
            value ? [0, -1, -1] : [-1, 0, -1],
        );
        assert.deepEqual(
            radios.map((radio) => radio.props['aria-checked']),
            [value, !value, false],
        );
    }
});

test('the first option is a Tab stop when no option is checked', () => {
    assert.deepEqual(
        render('missing').radios.map((radio) => radio.props.tabIndex),
        [0, -1, -1],
    );
});

for (const [key, targets] of [
    ['ArrowRight', [1, 2, 0]],
    ['ArrowDown', [1, 2, 0]],
    ['ArrowLeft', [2, 0, 1]],
    ['ArrowUp', [2, 0, 1]],
] as const) {
    test(`${key} selects and focuses the next option, wrapping at the ends`, () => {
        const values = [true, false, null];
        for (const index of [0, 1, 2]) {
            const { press, buttons, onChange } = render(values[index]);
            assert.equal(press(index, key).mock.calls.length, 1);
            assert.equal(buttons[targets[index]].focus.mock.calls.length, 1);
            assert.deepEqual(onChange.mock.calls, [[values[targets[index]]]]);
        }
    });
}

test('Home and End select and focus the first and last options', () => {
    for (const [key, from, to] of [
        ['Home', 2, 0],
        ['End', 0, 2],
    ] as const) {
        const values = [true, false, null];
        const { press, buttons, onChange } = render(values[from]);
        assert.equal(press(from, key).mock.calls.length, 1);
        assert.equal(buttons[to].focus.mock.calls.length, 1);
        assert.deepEqual(onChange.mock.calls, [[values[to]]]);
    }
});

test('navigation to the checked option does not fire another change', () => {
    const { press, buttons, onChange } = render();
    press(0, 'Home');
    assert.equal(buttons[0].focus.mock.calls.length, 1);
    assert.equal(onChange.mock.calls.length, 0);
});

test('rapid navigation can return to the initial value before its parent updates', () => {
    const { press, onChange } = render();
    press(0, 'ArrowRight');
    press(1, 'ArrowRight');
    press(2, 'ArrowRight');
    assert.deepEqual(onChange.mock.calls, [[false], [null], [true]]);
});

test('Tab, Space, Enter, and modified arrow keys retain their default behavior', () => {
    for (const [key, modifiers] of [
        ['Tab', {}],
        [' ', {}],
        ['Enter', {}],
        ['ArrowLeft', { altKey: true }],
        ['ArrowRight', { ctrlKey: true }],
        ['ArrowRight', { metaKey: true }],
    ] as const) {
        const { press, buttons, onChange } = render();
        assert.equal(press(0, key, modifiers).mock.calls.length, 0);
        assert.equal(onChange.mock.calls.length, 0);
        assert.ok(
            buttons.every((button) => button.focus.mock.calls.length === 0),
        );
    }
});

test('clicking an option still changes its value', () => {
    const { radios, onChange } = render();
    radios[1].props.onClick();
    assert.deepEqual(onChange.mock.calls, [[false]]);
});
