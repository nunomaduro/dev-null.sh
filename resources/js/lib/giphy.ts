export type GiphyAttachment = {
    id: string;
    url: string;
    page_url: string;
    title: string | null;
    username: string | null;
    width: number;
    height: number;
};

type GiphyRendition = { url: string; width: string; height: string };

export type GiphyResult = {
    id: string;
    title: string;
    username: string;
    url: string;
    images: Partial<
        Record<
            | 'downsized_medium'
            | 'downsized'
            | 'original'
            | 'fixed_height_small',
            GiphyRendition
        >
    >;
};

export class GiphyRateLimited extends Error {
    constructor() {
        super('Your GIPHY key is rate limiting searches.');
    }
}

export const pageSize = 24;

const baseUrl = 'https://api.giphy.com/v1/gifs';

const params = (key: string, offset: number): URLSearchParams =>
    new URLSearchParams({
        api_key: key,
        limit: String(pageSize),
        offset: String(offset),
        rating: 'g',
    });

export function giphySearchUrl(key: string, query: string, offset = 0): string {
    const search = params(key, offset);
    search.set('q', query);

    return `${baseUrl}/search?${search}`;
}

export function giphyTrendingUrl(key: string, offset = 0): string {
    return `${baseUrl}/trending?${params(key, offset)}`;
}

/** GIPHY requires client-side API calls, so this runs in the browser. */
export async function fetchGifs(
    url: string,
    signal?: AbortSignal,
): Promise<GiphyResult[]> {
    const response = await fetch(url, { signal });

    if (response.status === 429) {
        throw new GiphyRateLimited();
    }

    if (!response.ok) {
        throw new Error(`GIPHY request failed: ${response.status}`);
    }

    const data = (await response.json()) as { data: GiphyResult[] };

    return data.data;
}

export type GiphyKeyValidation =
    | 'valid'
    | 'invalid'
    | 'rate-limited'
    | 'unreachable';

/** Verifies a pasted key from the browser; a throttled key is still real. */
export async function validateGiphyKey(
    key: string,
): Promise<GiphyKeyValidation> {
    let response: Response;

    try {
        response = await fetch(giphyTrendingUrl(key));
    } catch {
        return 'unreachable';
    }

    if (response.ok) {
        return 'valid';
    }

    if (response.status === 401 || response.status === 403) {
        return 'invalid';
    }

    return response.status === 429 ? 'rate-limited' : 'unreachable';
}

/** Small fixed-height rendition for the picker grid. */
export function previewUrlFor(result: GiphyResult): string | null {
    const rendition =
        result.images.fixed_height_small ??
        result.images.downsized ??
        result.images.downsized_medium ??
        result.images.original;

    return rendition?.url ?? null;
}

/** Stored rendition: a .gif around feed size, kept verbatim per GIPHY's terms. */
export function attachmentFromResult(
    result: GiphyResult,
): GiphyAttachment | null {
    const rendition =
        result.images.downsized_medium ??
        result.images.downsized ??
        result.images.original;

    if (rendition === undefined) {
        return null;
    }

    return {
        id: result.id,
        url: rendition.url,
        page_url: result.url,
        title: result.title === '' ? null : result.title,
        username: result.username === '' ? null : result.username,
        width: Number.parseInt(rendition.width, 10),
        height: Number.parseInt(rendition.height, 10),
    };
}

/** Hidden fields every composer submits for an attached GIF. */
export function giphyFormFields(
    gif: GiphyAttachment,
): Record<string, string | number> {
    return {
        giphy_id: gif.id,
        giphy_url: gif.url,
        giphy_page_url: gif.page_url,
        giphy_title: gif.title ?? '',
        giphy_username: gif.username ?? '',
        giphy_width: gif.width,
        giphy_height: gif.height,
    };
}
