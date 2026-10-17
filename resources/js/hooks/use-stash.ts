import type { FocusEvent } from 'react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import {
    maxImages,
    pickImages,
    revokeObjectUrls,
} from '@/components/commit-image-picker';
import { readStoredGif, writeStoredGif } from '@/lib/gif-storage';
import type { GiphyAttachment } from '@/lib/giphy';
import type { StoredImages } from '@/lib/image-storage';
import {
    deleteStoredImages,
    readStoredImages,
    writeStoredImages,
} from '@/lib/image-storage';
import { stashableBody } from '@/lib/sensitive-text';
import { readStorage, storedKeys, writeStorage } from '@/lib/storage';

export type UseStashReturn = {
    readonly body: string;
    readonly images: File[];
    readonly descriptions: string[];
    readonly gif: GiphyAttachment | null;
    readonly isRestored: boolean;
    readonly updateBody: (body: string) => void;
    readonly updateImages: (images: File[], descriptions?: string[]) => void;
    readonly updateGif: (gif: GiphyAttachment | null) => void;
    readonly drop: () => void;
};

const listeners = new Set<() => void>();
const pending = new Map<string, string>();
const images = new Map<string, StoredImages>();
const gifs = new Map<string, GiphyAttachment | null>();
const restoredImageKeys = new Set<string>();
const noImages: StoredImages = { images: [], descriptions: [] };
let timeout: ReturnType<typeof setTimeout> | undefined;
let isListeningForPageHide = false;
let isLoadingImages = false;

const prefixFor = (userId: string): string => `stash:${userId}:`;

const keyFor = (userId: string, name: string): string =>
    `${prefixFor(userId)}${name}`;

const read = (key: string): string =>
    pending.has(key) ? (pending.get(key) ?? '') : readStorage(key);

const imagesFor = (key: string): StoredImages => images.get(key) ?? noImages;

const gifFor = (key: string): GiphyAttachment | null => {
    if (!gifs.has(key)) {
        gifs.set(key, readStoredGif(key));
    }

    return gifs.get(key) ?? null;
};

const storeGif = (key: string, gif: GiphyAttachment | null): void => {
    gifs.set(key, gif);
    writeStoredGif(key, gif);
    notify();
};

const useDraftImages = (key: string) => {
    const draftImages = useSyncExternalStore(
        subscribe,
        () => imagesFor(key),
        () => noImages,
    );
    const isImagesRestored = useSyncExternalStore(
        subscribe,
        () => restoredImageKeys.has(key),
        () => false,
    );

    return {
        draftImages: draftImages.images,
        descriptions: draftImages.descriptions,
        isImagesRestored,
    };
};

const notify = (): void => listeners.forEach((listener) => listener());

const loadImages = (): void => {
    if (isLoadingImages) {
        return;
    }

    isLoadingImages = true;

    void readStoredImages().then((stored) => {
        stored.forEach((draft, key) => {
            const restored = draft.images
                .map((file, index) => ({
                    file,
                    description: draft.descriptions[index] ?? '',
                }))
                .filter(({ file }) => pickImages([], [file]).images.length > 0)
                .slice(0, maxImages);

            if (!images.has(key) && restored.length > 0) {
                images.set(key, {
                    images: restored.map((entry) => entry.file),
                    descriptions: restored.map((entry) => entry.description),
                });
                restoredImageKeys.add(key);
            }
        });

        notify();
    });
};

const subscribe = (callback: () => void) => {
    loadImages();
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const flush = (): void => {
    clearTimeout(timeout);
    pending.forEach((body, key) => writeStorage(key, stashableBody(body)));
    pending.clear();
    notify();
};

const schedule = (key: string, body: string): void => {
    if (!isListeningForPageHide) {
        window.addEventListener('pagehide', flush);
        isListeningForPageHide = true;
    }

    pending.set(key, body);
    clearTimeout(timeout);
    timeout = setTimeout(flush, 300);
};

const discardBody = (key: string): void => {
    pending.delete(key);
    writeStorage(key, '');
    notify();
};

const storeImages = (
    key: string,
    next: File[],
    descriptions: string[] = [],
): void => {
    if (next.length === 0) {
        restoredImageKeys.delete(key);
    }

    if (next.length === 0 && images.get(key)?.images.length === 0) {
        return;
    }

    revokeObjectUrls(
        imagesFor(key).images.filter((image) => !next.includes(image)),
    );

    const nextDescriptions = next.map((_, index) => descriptions[index] ?? '');
    images.set(key, { images: next, descriptions: nextDescriptions });
    writeStoredImages(key, next, nextDescriptions);
    notify();
};

export function moveCaretToEnd(event: FocusEvent<HTMLTextAreaElement>): void {
    const textarea = event.currentTarget;

    if (textarea.selectionStart === 0 && textarea.selectionEnd === 0) {
        textarea.setSelectionRange(
            textarea.value.length,
            textarea.value.length,
        );
    }
}

/**
 * Drafts of series kept their later parts there. Only the first part survives, as the commit being written.
 */
export function discardSeriesStash(userId: string): void {
    writeStorage(`${keyFor(userId, 'commit')}:later`, '');
}

export function clearStashes(userId: string): void {
    const prefix = prefixFor(userId);

    [...pending.keys(), ...storedKeys()]
        .filter((key) => key.startsWith(prefix))
        .forEach((key) => {
            pending.delete(key);
            writeStorage(key, '');
        });

    [...images.keys()]
        .filter((key) => key.startsWith(prefix))
        .forEach((key) => {
            revokeObjectUrls(imagesFor(key).images);
            images.delete(key);
            restoredImageKeys.delete(key);
        });

    [...gifs.keys()]
        .filter((key) => key.startsWith(prefix))
        .forEach((key) => gifs.delete(key));

    deleteStoredImages(prefix);
    notify();
}

/**
 * Guests never stash anything, as they sign in before they write.
 */
export function useHasStash(userId: string | null, name: string): boolean {
    const key = userId === null ? null : keyFor(userId, name);

    return useSyncExternalStore(
        subscribe,
        () =>
            key !== null &&
            (read(key) !== '' ||
                imagesFor(key).images.length > 0 ||
                gifFor(key) !== null),
        () => false,
    );
}

export function useStash(userId: string, name: string): UseStashReturn {
    const key = keyFor(userId, name);
    const [body, setBody] = useState(() => read(key));
    const { draftImages, descriptions, isImagesRestored } = useDraftImages(key);
    const gif = useSyncExternalStore(
        subscribe,
        () => gifFor(key),
        () => null,
    );
    const [isDraftRestored, setIsDraftRestored] = useState(
        () => body !== '' || draftImages.length > 0 || gifFor(key) !== null,
    );

    useEffect(() => {
        if (body !== '' && stashableBody(body) === '') {
            discardBody(key);
        }
    }, [body, key]);

    const updateBody = (next: string): void => {
        setBody(next);

        if (next === '') {
            if (draftImages.length === 0) {
                setIsDraftRestored(false);
            }

            discardBody(key);

            return;
        }

        if (stashableBody(next) === '') {
            discardBody(key);
        } else {
            schedule(key, next);
        }
    };

    const updateImages = (next: File[], descriptions: string[] = []): void => {
        if (next.length === 0 && body === '') {
            setIsDraftRestored(false);
        }

        storeImages(key, next, descriptions);
    };

    const updateGif = (next: GiphyAttachment | null): void => {
        if (next === null && body === '' && draftImages.length === 0) {
            setIsDraftRestored(false);
        }

        storeGif(key, next);
    };

    const drop = (): void => {
        setBody('');
        setIsDraftRestored(false);
        discardBody(key);
        storeImages(key, []);
        storeGif(key, null);
    };

    return {
        body,
        images: draftImages,
        descriptions,
        gif,
        isRestored:
            (isDraftRestored || isImagesRestored) &&
            (body !== '' || draftImages.length > 0 || gif !== null),
        updateBody,
        updateImages,
        updateGif,
        drop,
    } as const;
}
