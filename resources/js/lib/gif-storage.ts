import type { GiphyAttachment } from './giphy.ts';
import { readStorage, writeStorage } from './storage.ts';

const gifKey = (key: string): string => `${key}:gif`;

/** A stashed GIF is tiny JSON in localStorage; images are Files in IndexedDB. */
export function readStoredGif(key: string): GiphyAttachment | null {
    const raw = readStorage(gifKey(key));

    if (raw === '') {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as Partial<GiphyAttachment>;

        return typeof parsed.id === 'string' &&
            typeof parsed.url === 'string' &&
            typeof parsed.page_url === 'string'
            ? (parsed as GiphyAttachment)
            : null;
    } catch {
        return null;
    }
}

export function writeStoredGif(key: string, gif: GiphyAttachment | null): void {
    writeStorage(gifKey(key), gif === null ? '' : JSON.stringify(gif));
}
