import assert from 'node:assert/strict';
import { test } from 'node:test';
import { insertAtSelection } from '../../resources/js/lib/text-selection.ts';

void test('inserts at the caret and moves the caret after the insertion', () => {
    assert.deepEqual(
        insertAtSelection(
            'shipped it',
            { selectionStart: 8, selectionEnd: 8 },
            '🚀 ',
            280,
        ),
        { value: 'shipped 🚀 it', caret: 11 },
    );
});

void test('replaces the selected text', () => {
    assert.deepEqual(
        insertAtSelection(
            'shipped it',
            { selectionStart: 8, selectionEnd: 10 },
            '🎉',
            280,
        ),
        { value: 'shipped 🎉', caret: 10 },
    );
});

void test('appends without a selection', () => {
    assert.deepEqual(insertAtSelection('shipped', null, ' 🔥', 280), {
        value: 'shipped 🔥',
        caret: 10,
    });
});

void test('refuses an insertion that would not fit, counting characters like the composer', () => {
    assert.deepEqual(insertAtSelection('a'.repeat(279), null, '🔥', 280), {
        value: `${'a'.repeat(279)}🔥`,
        caret: 281,
    });
    assert.equal(insertAtSelection('a'.repeat(280), null, '🔥', 280), null);
});
