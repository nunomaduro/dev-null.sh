import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    canGoFullscreen,
    goFullscreen,
    playbackFromMessage,
    playerApiSrc,
    subscriptionMessages,
} from '../../resources/js/lib/video-player-state.ts';
import type { FullscreenTarget } from '../../resources/js/lib/video-player-state.ts';

const youtube = 'https://www.youtube-nocookie.com/embed/5C_HPTJg5ek?autoplay=1';
const vimeo = 'https://player.vimeo.com/video/76979871?autoplay=1';
const loom = 'https://www.loom.com/embed/abc123?autoplay=1';

await test('asks YouTube to report its state to this page', () => {
    const url = new URL(playerApiSrc(youtube, 'http://localhost:8000'));

    assert.equal(url.searchParams.get('autoplay'), '1');
    assert.equal(url.searchParams.get('enablejsapi'), '1');
    assert.equal(url.searchParams.get('origin'), 'http://localhost:8000');
});

await test('leaves other players as they are', () => {
    assert.equal(playerApiSrc(vimeo, 'http://localhost:8000'), vimeo);
    assert.equal(playerApiSrc(loom, 'http://localhost:8000'), loom);
    assert.equal(playerApiSrc('not a url', 'http://localhost:8000'), 'not a url');
});

await test('reads YouTube state changes', () => {
    const change = (info: number) =>
        playbackFromMessage(
            youtube,
            JSON.stringify({ event: 'onStateChange', info }),
        );

    assert.equal(change(1), 'playing');
    assert.equal(change(3), 'playing');
    assert.equal(change(2), 'paused');
    assert.equal(change(0), 'paused');
    assert.equal(change(-1), 'paused');
    assert.equal(change(5), 'paused');
    assert.equal(change(42), null);
});

await test('reads the state YouTube reports with its other details', () => {
    assert.equal(
        playbackFromMessage(
            youtube,
            JSON.stringify({
                event: 'infoDelivery',
                info: { playerState: 1, currentTime: 12 },
            }),
        ),
        'playing',
    );
    assert.equal(
        playbackFromMessage(
            youtube,
            JSON.stringify({ event: 'infoDelivery', info: { currentTime: 12 } }),
        ),
        null,
    );
});

await test('reads Vimeo events', () => {
    assert.equal(playbackFromMessage(vimeo, { event: 'play' }), 'playing');
    assert.equal(playbackFromMessage(vimeo, '{"event":"pause"}'), 'paused');
    assert.equal(playbackFromMessage(vimeo, { event: 'ended' }), 'paused');
    assert.equal(playbackFromMessage(vimeo, { event: 'ready' }), null);
});

await test('ignores messages it cannot read', () => {
    assert.equal(playbackFromMessage(youtube, 'not json'), null);
    assert.equal(playbackFromMessage(youtube, null), null);
    assert.equal(playbackFromMessage(loom, { event: 'play' }), null);
});

await test('subscribes to the players that report their state', () => {
    assert.equal(subscriptionMessages(youtube).length, 2);
    assert.deepEqual(
        subscriptionMessages(vimeo).map((message) => JSON.parse(message)),
        [
            { method: 'addEventListener', value: 'play' },
            { method: 'addEventListener', value: 'pause' },
            { method: 'addEventListener', value: 'ended' },
        ],
    );
    assert.deepEqual(subscriptionMessages(loom), []);
});

await test('knows when the page can go full screen', () => {
    assert.equal(canGoFullscreen({ fullscreenEnabled: true }), true);
    assert.equal(
        canGoFullscreen({
            fullscreenEnabled: false,
            webkitFullscreenEnabled: true,
        }),
        true,
    );
    assert.equal(canGoFullscreen({ fullscreenEnabled: false }), false);
});

await test('puts the player full screen', () => {
    let requests = 0;

    goFullscreen({
        requestFullscreen: () => {
            requests++;

            return Promise.resolve();
        },
    } as unknown as FullscreenTarget);

    assert.equal(requests, 1);
});

await test('falls back to the prefixed full screen', () => {
    let requests = 0;

    goFullscreen({
        webkitRequestFullscreen: () => requests++,
    } as unknown as FullscreenTarget);

    assert.equal(requests, 1);
});

await test('shrugs off a refused full screen', async () => {
    goFullscreen({
        requestFullscreen: () => Promise.reject(new Error('Permissions check failed')),
    } as unknown as FullscreenTarget);

    await new Promise((resolve) => setTimeout(resolve, 0));
});
