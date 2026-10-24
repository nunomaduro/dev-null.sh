import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cardPlacement,
    nextIndex,
    visibleLive,
} from '../../resources/js/lib/orbit-rail.ts';

const now = new Date('2026-10-24T16:00:00Z');

function live(id: string, endsAt: string) {
    return { id, live: { ends_at: endsAt } };
}

void test('drops people whose stream has ended and keeps the soonest-ending order', () => {
    const users = [
        live('ended', '2026-10-24T15:59:00Z'),
        live('ending-now', '2026-10-24T16:00:00Z'),
        live('soon', '2026-10-24T16:00:01Z'),
        live('later', '2026-10-24T18:00:00Z'),
    ];

    assert.deepEqual(
        visibleLive(users, now).map((user) => user.id),
        ['soon', 'later'],
    );
});

void test('moves through the rail with the arrow keys, wrapping at both ends', () => {
    assert.equal(nextIndex(0, 'ArrowRight', 3), 1);
    assert.equal(nextIndex(2, 'ArrowRight', 3), 0);
    assert.equal(nextIndex(1, 'ArrowLeft', 3), 0);
    assert.equal(nextIndex(0, 'ArrowLeft', 3), 2);
});

void test('jumps to the first and last slot with Home and End', () => {
    assert.equal(nextIndex(2, 'Home', 5), 0);
    assert.equal(nextIndex(1, 'End', 5), 4);
});

void test('leaves other keys and an empty rail alone', () => {
    assert.equal(nextIndex(0, 'Enter', 3), null);
    assert.equal(nextIndex(0, 'ArrowDown', 3), null);
    assert.equal(nextIndex(0, 'ArrowRight', 0), null);
});

void test('opens the card just left of the ring it belongs to', () => {
    const placement = cardPlacement({
        slot: { left: 380, width: 56 },
        column: { left: 300, width: 620 },
    });

    assert.deepEqual(placement, { width: 520, alignOffset: -36, originX: 64 });
});

void test('keeps the card inside the feed column near either edge', () => {
    const first = cardPlacement({
        slot: { left: 316, width: 56 },
        column: { left: 300, width: 620 },
    });
    const last = cardPlacement({
        slot: { left: 856, width: 56 },
        column: { left: 300, width: 620 },
    });

    assert.deepEqual(first, { width: 520, alignOffset: 0, originX: 28 });
    // The card ends 16px inside the column's right edge, and the line still lands under the ring.
    assert.deepEqual(last, { width: 520, alignOffset: -472, originX: 500 });
});

void test('narrows the card to the column on a phone', () => {
    assert.deepEqual(
        cardPlacement({
            slot: { left: 76, width: 56 },
            column: { left: 0, width: 375 },
        }),
        { width: 343, alignOffset: -60, originX: 88 },
    );
});

void test('points the line at the card corner when the ring has scrolled out of view', () => {
    const placement = cardPlacement({
        slot: { left: -200, width: 56 },
        column: { left: 0, width: 620 },
    });

    assert.equal(placement.originX, 8);
});
