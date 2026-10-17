import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    attachmentFromResult,
    giphyFormFields,
    giphySearchUrl,
    giphyTrendingUrl,
    previewUrlFor,
    validateGiphyKey,
} from '../../resources/js/lib/giphy.ts';
import type { GiphyResult } from '../../resources/js/lib/giphy.ts';

const result: GiphyResult = {
    id: 'xT9IgzoKnwFNmISR8I',
    title: 'Deal With It GIF',
    username: 'giphy',
    url: 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
    images: {
        fixed_height_small: {
            url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/100.gif?cid=abc',
            width: '178',
            height: '100',
        },
        downsized_medium: {
            url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc',
            width: '480',
            height: '270',
        },
        downsized: {
            url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized.gif?cid=abc',
            width: '400',
            height: '225',
        },
    },
};

await test('builds a search url with the g rating always on', () => {
    const url = new URL(giphySearchUrl('key-123', 'deal with it', 24));
    assert.equal(url.origin + url.pathname, 'https://api.giphy.com/v1/gifs/search');
    assert.equal(url.searchParams.get('api_key'), 'key-123');
    assert.equal(url.searchParams.get('q'), 'deal with it');
    assert.equal(url.searchParams.get('rating'), 'g');
    assert.equal(url.searchParams.get('offset'), '24');
});

await test('builds a trending url with the g rating always on', () => {
    const url = new URL(giphyTrendingUrl('key-123'));
    assert.equal(url.origin + url.pathname, 'https://api.giphy.com/v1/gifs/trending');
    assert.equal(url.searchParams.get('rating'), 'g');
    assert.equal(url.searchParams.get('offset'), '0');
});

await test('picks the downsized_medium gif rendition verbatim', () => {
    const gif = attachmentFromResult(result);
    assert.deepEqual(gif, {
        id: 'xT9IgzoKnwFNmISR8I',
        url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc',
        page_url: 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        title: 'Deal With It GIF',
        username: 'giphy',
        width: 480,
        height: 270,
    });
});

await test('falls back through downsized to original and bails without any', () => {
    const { downsized_medium: _dropped, ...rest } = result.images;
    const fallback = attachmentFromResult({ ...result, images: rest });
    assert.equal(
        fallback?.url,
        'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized.gif?cid=abc',
    );
    assert.equal(attachmentFromResult({ ...result, images: {} }), null);
});

await test('turns empty title and username into null', () => {
    const gif = attachmentFromResult({ ...result, title: '', username: '' });
    assert.equal(gif?.title, null);
    assert.equal(gif?.username, null);
});

await test('uses the small fixed-height rendition for previews', () => {
    assert.equal(
        previewUrlFor(result),
        'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/100.gif?cid=abc',
    );
    assert.equal(
        previewUrlFor({ ...result, images: { downsized: result.images.downsized } }),
        'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized.gif?cid=abc',
    );
    assert.equal(previewUrlFor({ ...result, images: {} }), null);
});

await test('shapes the form fields the server expects', () => {
    const gif = attachmentFromResult(result);
    assert.ok(gif);
    assert.deepEqual(giphyFormFields(gif), {
        giphy_id: 'xT9IgzoKnwFNmISR8I',
        giphy_url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc',
        giphy_page_url: 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
        giphy_title: 'Deal With It GIF',
        giphy_username: 'giphy',
        giphy_width: 480,
        giphy_height: 270,
    });
});

const originalFetch = globalThis.fetch;

function stubFetch(result: number | 'network'): string[] {
    const calls: string[] = [];

    globalThis.fetch = (async (input: RequestInfo | URL) => {
        calls.push(String(input));

        if (result === 'network') {
            throw new TypeError('fetch failed');
        }

        return new Response('{}', { status: result });
    }) as typeof fetch;

    return calls;
}

function restoreFetch(): void {
    globalThis.fetch = originalFetch;
}

await test('validates a key against the trending endpoint', async () => {
    const calls = stubFetch(200);

    try {
        assert.equal(await validateGiphyKey('key-123'), 'valid');
        assert.ok(calls[0]?.includes('api_key=key-123'));
        assert.ok(calls[0]?.includes('rating=g'));
    } finally {
        restoreFetch();
    }
});

await test('treats a refused key as invalid', async () => {
    for (const status of [401, 403]) {
        stubFetch(status);

        try {
            assert.equal(await validateGiphyKey('key-123'), 'invalid');
        } finally {
            restoreFetch();
        }
    }
});

await test('treats a throttled key as rate limited but real', async () => {
    stubFetch(429);

    try {
        assert.equal(await validateGiphyKey('key-123'), 'rate-limited');
    } finally {
        restoreFetch();
    }
});

await test('treats network failures and unexpected statuses as unreachable', async () => {
    stubFetch('network');

    try {
        assert.equal(await validateGiphyKey('key-123'), 'unreachable');
    } finally {
        restoreFetch();
    }

    stubFetch(500);

    try {
        assert.equal(await validateGiphyKey('key-123'), 'unreachable');
    } finally {
        restoreFetch();
    }
});
