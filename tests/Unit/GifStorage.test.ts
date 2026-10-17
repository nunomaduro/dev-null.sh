import assert from 'node:assert/strict';
import { test } from 'node:test';

const store = new Map<string, string>();
(globalThis as Record<string, unknown>).window = {
    localStorage: {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => void store.set(key, value),
        removeItem: (key: string) => void store.delete(key),
    },
};

const { readStoredGif, writeStoredGif } = await import(
    '../../resources/js/lib/gif-storage.ts'
);

const gif = {
    id: 'xT9IgzoKnwFNmISR8I',
    url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123',
    page_url: 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
    title: 'Deal With It GIF',
    username: 'giphy',
    width: 480,
    height: 270,
};

await test('round-trips a stashed gif', () => {
    writeStoredGif('stash:u1:commit', gif);
    assert.deepEqual(readStoredGif('stash:u1:commit'), gif);
});

await test('clears a stashed gif', () => {
    writeStoredGif('stash:u1:commit', gif);
    writeStoredGif('stash:u1:commit', null);
    assert.equal(readStoredGif('stash:u1:commit'), null);
});

await test('ignores unreadable stashed values', () => {
    store.set('stash:u1:reply-1:gif', 'not json');
    assert.equal(readStoredGif('stash:u1:reply-1'), null);
    store.set('stash:u1:reply-2:gif', JSON.stringify({ nope: true }));
    assert.equal(readStoredGif('stash:u1:reply-2'), null);
});
