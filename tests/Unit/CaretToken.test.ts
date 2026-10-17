import assert from 'node:assert/strict';
import { test } from 'node:test';
import { caretToken } from '../../resources/js/lib/caret-token.ts';

const at = (value: string, caret = value.length) => ({
    value,
    selectionStart: caret,
    selectionEnd: caret,
});

void test('finds the mention token being typed before the caret', () => {
    assert.deepEqual(caretToken(at('hello @al')), {
        kind: 'mention',
        start: 6,
        query: 'al',
    });
    assert.deepEqual(caretToken(at('@')), {
        kind: 'mention',
        start: 0,
        query: '',
    });
    assert.deepEqual(caretToken(at('hi\n@alice')), {
        kind: 'mention',
        start: 3,
        query: 'alice',
    });
});

void test('does not treat emails, scoped packages, or domains as mentions', () => {
    assert.equal(caretToken(at('me@example.com')), null);
    assert.equal(caretToken(at('@inertiajs/react')), null);
    assert.equal(caretToken(at('@vue.js')), null);
});

void test('finds the slash token being typed before the caret', () => {
    assert.deepEqual(caretToken(at('/')), {
        kind: 'slash',
        start: 0,
        query: '',
    });
    assert.deepEqual(caretToken(at('/im')), {
        kind: 'slash',
        start: 0,
        query: 'im',
    });
    assert.deepEqual(caretToken(at('hi /po')), {
        kind: 'slash',
        start: 3,
        query: 'po',
    });
    assert.deepEqual(caretToken(at('hi\n/code')), {
        kind: 'slash',
        start: 3,
        query: 'code',
    });
});

void test('does not trigger on URLs, paths, fractions, or mid-word slashes', () => {
    assert.equal(caretToken(at('https://example.com')), null);
    assert.equal(caretToken(at('https://example')), null);
    assert.equal(caretToken(at('path/to')), null);
    assert.equal(caretToken(at('1/2')), null);
    assert.equal(caretToken(at('and/or')), null);
    assert.equal(caretToken(at('@user/name')), null);
});

void test('slash commands are lowercase only and require a collapsed caret', () => {
    assert.equal(caretToken(at('/Image')), null);
    assert.equal(
        caretToken({ value: '/image', selectionStart: 3, selectionEnd: 6 }),
        null,
    );
});

void test('finds the emoji shortcode being typed in prose before the caret', () => {
    assert.deepEqual(caretToken(at('hi :sm'), 'hi :sm'), {
        kind: 'emoji',
        start: 3,
        query: 'sm',
    });
    assert.deepEqual(caretToken(at('ship :fire'), 'ship :fire'), {
        kind: 'emoji',
        start: 5,
        query: 'fire',
    });
});

void test('emoji stay quiet on times, one-letter shortcodes, and outside prose', () => {
    assert.equal(caretToken(at('10:30'), '10:30'), null);
    assert.equal(caretToken(at('a:bc'), 'a:bc'), null);
    assert.equal(caretToken(at(':a'), ':a'), null);
    assert.equal(caretToken(at('`x :sm`'), ''), null);
    assert.equal(caretToken(at('hi :sm')), null);
});
