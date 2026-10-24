import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    arrivalOf,
    shouldScrollToFocusedCommit,
} from '../../resources/js/lib/focused-commit-arrival.ts';

const reply = {
    component: 'commit/show',
    arrival: 'link',
    keepsPlace: false,
    ancestorCount: 3,
} as const;

await test('tells a link from a reload and from going back', () => {
    assert.equal(arrivalOf('visit', 'navigate'), 'link');
    assert.equal(arrivalOf('initial', 'navigate'), 'link');
    assert.equal(arrivalOf('initial', undefined), 'link');
    assert.equal(arrivalOf('initial', 'reload'), 'reload');
    assert.equal(arrivalOf('initial', 'back_forward'), 'history');
    assert.equal(arrivalOf('history', 'navigate'), 'history');
});

await test('scrolls to a reply opened from a link, below its thread', () => {
    assert.equal(shouldScrollToFocusedCommit(reply), true);
});

await test('leaves a commit with nothing above it at the top', () => {
    assert.equal(
        shouldScrollToFocusedCommit({ ...reply, ancestorCount: 0 }),
        false,
    );
});

await test('keeps the position the browser restores on reload and going back', () => {
    assert.equal(
        shouldScrollToFocusedCommit({ ...reply, arrival: 'reload' }),
        false,
    );
    assert.equal(
        shouldScrollToFocusedCommit({ ...reply, arrival: 'history' }),
        false,
    );
});

await test('keeps the reader in place when a visit keeps the page state or scroll', () => {
    assert.equal(
        shouldScrollToFocusedCommit({ ...reply, keepsPlace: true }),
        false,
    );
});

await test('only scrolls the commit page', () => {
    assert.equal(
        shouldScrollToFocusedCommit({ ...reply, component: 'log/show' }),
        false,
    );
});
