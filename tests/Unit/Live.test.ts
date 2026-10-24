import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatEndsIn,
    formatLiveUntil,
    isStreaming,
    thumbnailUrl,
} from '../../resources/js/lib/live.ts';

const now = new Date('2026-10-24T16:00:00Z');

void test('a stream is on until the moment it ends', () => {
    assert.equal(isStreaming({ ends_at: '2026-10-24T16:00:01Z' }, now), true);
    assert.equal(isStreaming({ ends_at: '2026-10-24T16:00:00Z' }, now), false);
    assert.equal(isStreaming({ ends_at: '2026-10-24T15:00:00Z' }, now), false);
});

void test('the end time is the clock time in the viewer timezone', () => {
    assert.match(
        formatLiveUntil('2026-10-24T16:00:00Z', 'Europe/Berlin'),
        /^(18:00|6:00\sPM)$/,
    );
    assert.match(
        formatLiveUntil('2026-10-24T16:05:00Z', 'America/New_York'),
        /^(12:05|12:05\sPM)$/,
    );
});

void test('the time left rounds up to whole minutes with padded minutes after hours', () => {
    assert.equal(formatEndsIn('2026-10-24T18:10:00Z', now), '2h 10m');
    assert.equal(formatEndsIn('2026-10-24T17:07:01Z', now), '1h 08m');
    assert.equal(formatEndsIn('2026-10-24T18:00:00Z', now), '2h 00m');
    assert.equal(formatEndsIn('2026-10-24T16:45:00Z', now), '45m');
    assert.equal(formatEndsIn('2026-10-24T16:00:01Z', now), '1m');
});

void test('a stream that is over has no time left', () => {
    assert.equal(formatEndsIn('2026-10-24T16:00:00Z', now), '0m');
    assert.equal(formatEndsIn('2026-10-24T12:00:00Z', now), '0m');
});

void test('the thumbnail URL stays the same within five minutes and changes after', () => {
    const stream = {
        thumbnail_url:
            'https://static-cdn.jtvnw.net/previews-ttv/live_user_dev-1280x720.jpg',
    };
    const first = thumbnailUrl(stream, new Date('2026-10-24T16:00:00Z'));

    assert.equal(thumbnailUrl(stream, new Date('2026-10-24T16:04:59Z')), first);
    assert.notEqual(
        thumbnailUrl(stream, new Date('2026-10-24T16:05:00Z')),
        first,
    );
    assert.match(
        first,
        /^https:\/\/static-cdn\.jtvnw\.net\/previews-ttv\/live_user_dev-1280x720\.jpg\?t=\d+$/,
    );
});

void test('the thumbnail cache bust keeps the query the URL already has', () => {
    const url = new URL(
        thumbnailUrl(
            {
                thumbnail_url:
                    'https://i.ytimg.com/vi/abc/hqdefault_live.jpg?v=2',
            },
            now,
        ),
    );

    assert.equal(url.searchParams.get('v'), '2');
    assert.ok(url.searchParams.has('t'));
    assert.equal(url.pathname, '/vi/abc/hqdefault_live.jpg');
});
