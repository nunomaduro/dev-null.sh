import assert from 'node:assert/strict';
import { test } from 'node:test';
import { withLeavingRows } from '../../resources/js/lib/leaving-rows.ts';
import type { ListRow } from '../../resources/js/lib/leaving-rows.ts';

type Item = { id: number; label?: string };

const rows = (...ids: number[]): ListRow<Item>[] =>
    ids.map((id) => ({ item: { id }, isLeaving: false }));

const shown = (list: ListRow<Item>[]) =>
    list.map(({ item, isLeaving }) => (isLeaving ? `-${item.id}` : item.id));

await test('keeps a removed row where it was, leaving', () => {
    assert.deepEqual(
        shown(withLeavingRows(rows(1, 2, 3), [{ id: 1 }, { id: 3 }])),
        [1, '-2', 3],
    );
});

await test('keeps removed rows next to each other in their order', () => {
    assert.deepEqual(
        shown(withLeavingRows(rows(1, 2, 3, 4), [{ id: 1 }, { id: 4 }])),
        [1, '-2', '-3', 4],
    );
});

await test('keeps a removed first row first', () => {
    assert.deepEqual(shown(withLeavingRows(rows(1, 2), [{ id: 2 }])), [
        '-1',
        2,
    ]);
});

await test('keeps a removed row after the row it followed when new ones arrive first', () => {
    assert.deepEqual(
        shown(withLeavingRows(rows(1, 2), [{ id: 5 }, { id: 1 }])),
        [5, 1, '-2'],
    );
});

await test('keeps rows that are still leaving until they are dropped', () => {
    const leaving = withLeavingRows(rows(1, 2), [{ id: 1 }]);

    assert.deepEqual(shown(withLeavingRows(leaving, [{ id: 1 }])), [1, '-2']);
});

await test('brings back a row whose item returns', () => {
    const leaving = withLeavingRows(rows(1, 2), [{ id: 1 }]);

    assert.deepEqual(
        shown(withLeavingRows(leaving, [{ id: 1 }, { id: 2 }])),
        [1, 2],
    );
});

await test('shows the next version of the items that stay', () => {
    const next = withLeavingRows(rows(1), [{ id: 1, label: 'fresh' }]);

    assert.equal(next[0]?.item.label, 'fresh');
});
