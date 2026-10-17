import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { ComposerSurface } from '../../resources/js/lib/slash-commands.ts';
import {
    availableCommands,
    codeFenceInsertion,
    consumeSlashToken,
    filterCommands,
    matchCommand,
} from '../../resources/js/lib/slash-commands.ts';

const surface = (
    overrides: Partial<ComposerSurface> = {},
): ComposerSurface => ({
    surface: 'commit',
    hasPoll: false,
    imageCount: 0,
    isScheduling: false,
    processing: false,
    ...overrides,
});

void test('lists image, poll, and code with stock copy', () => {
    assert.deepEqual(
        availableCommands(surface()).map((option) => [
            option.command.id,
            option.enabled,
            option.reason,
        ]),
        [
            ['image', true, null],
            ['poll', true, null],
            ['code', true, null],
        ],
    );
});

void test('poll is only offered on top-level commits', () => {
    for (const kind of ['reply', 'quote'] as const) {
        assert.deepEqual(
            availableCommands(surface({ surface: kind })).map(
                (option) => option.command.id,
            ),
            ['image', 'code'],
        );
    }
});

void test('image is disabled with a reason by poll and the image limit', () => {
    const reason = (overrides: Partial<ComposerSurface>) =>
        availableCommands(surface(overrides)).find(
            (option) => option.command.id === 'image',
        )!.reason;

    assert.equal(reason({ hasPoll: true }), 'Remove the poll first');
    assert.equal(reason({ imageCount: 3 }), 'Maximum of 3 images');
});

void test('poll is disabled with a reason by images and an existing poll', () => {
    const reason = (overrides: Partial<ComposerSurface>) =>
        availableCommands(surface(overrides)).find(
            (option) => option.command.id === 'poll',
        )!.reason;

    assert.equal(reason({ imageCount: 1 }), 'Remove the images first');
    assert.equal(reason({ hasPoll: true }), 'A poll is already added');
});

void test('scheduling keeps images and polls available', () => {
    const options = availableCommands(surface({ isScheduling: true }));

    assert.ok(options.every((option) => option.enabled));
});

void test('every command is disabled without a reason while submitting', () => {
    const options = availableCommands(surface({ processing: true }));

    assert.ok(options.every((option) => !option.enabled));
    assert.ok(options.every((option) => option.reason === null));
});

void test('filters by prefix, aliases included', () => {
    const options = availableCommands(surface());
    const ids = (query: string) =>
        filterCommands(options, query).map((option) => option.command.id);

    assert.deepEqual(ids(''), ['image', 'poll', 'code']);
    assert.deepEqual(ids('i'), ['image']);
    assert.deepEqual(ids('img'), ['image']);
    assert.deepEqual(ids('po'), ['poll']);
    assert.deepEqual(ids('c'), ['code']);
    assert.deepEqual(ids('x'), []);
});

void test('resolves exact names and aliases for direct runs', () => {
    assert.equal(matchCommand('image')?.id, 'image');
    assert.equal(matchCommand('img')?.id, 'image');
    assert.equal(matchCommand('poll')?.id, 'poll');
    assert.equal(matchCommand('code')?.id, 'code');
    assert.equal(matchCommand('imagex'), null);
    assert.equal(matchCommand(''), null);
});

void test('consuming a token drops one adjacent space', () => {
    assert.equal(consumeSlashToken('ask /poll', 4, 'poll'), 'ask');
    assert.equal(consumeSlashToken('/image tell us', 0, 'image'), 'tell us');
    assert.equal(consumeSlashToken('/code', 0, 'code'), '');
    assert.equal(consumeSlashToken('line\n/image', 5, 'image'), 'line\n');
});

void test('a code insertion keeps the fences on their own lines', () => {
    assert.deepEqual(codeFenceInsertion('see /code', 4, 9), {
        value: 'see \n```\n\n```',
        caret: 9,
    });
    assert.deepEqual(codeFenceInsertion('/code', 0, 5), {
        value: '```\n\n```',
        caret: 4,
    });
    assert.deepEqual(codeFenceInsertion('see /code here', 4, 9), {
        value: 'see \n```\n\n```\n here',
        caret: 9,
    });
});
