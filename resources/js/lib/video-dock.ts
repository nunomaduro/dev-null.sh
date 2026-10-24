import { useSyncExternalStore } from 'react';
import { readStorage, writeStorage } from '@/lib/storage';
import {
    corners,
    defaultDockCorner,
    defaultDockLength,
    isCompact,
} from '@/lib/video-dock-geometry';
import type { DockPlacement, Size } from '@/lib/video-dock-geometry';

/**
 * The video playing on the page. Its player lives in the layout, so it keeps playing while the user moves between
 * pages: over the embed it was started from while that embed is on the page, and floating in a corner while it is not.
 * The page is told apart by its path alone, so a search narrowing its results keeps the video at home.
 */
export type DockedVideo = {
    src: string;
    title: string;
    aspectRatio: number;
    pagePath: string;
};

type Slot = { src: string; element: HTMLElement; owner: string };

/**
 * A phone and a wide screen each remember their own corner and size, so a player made large at a desk doesn't open
 * large on a phone.
 */
const storageKey = (viewport: Size): string =>
    isCompact(viewport) ? 'video-dock:compact' : 'video-dock';
const listeners = new Set<() => void>();
const slots: Slot[] = [];
let video: DockedVideo | null = null;
let playedFrom: Element | null = null;
let claimedSlot: HTMLElement | null = null;
let heldSlot: Slot | null = null;
let isFloating = false;
let isPopOutRequested = false;
let playingInPlace: string | null = null;

function notify(): void {
    listeners.forEach((listener) => listener());
}

export function subscribeToVideoDock(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function currentPagePath(): string {
    return window.location.pathname;
}

/**
 * Starting a video replaces the one playing, wherever it is: one player keeps the page from talking over itself. It
 * starts in the embed it was played from, even when the same video is posted higher up the page too.
 */
export function playVideo(
    next: Omit<DockedVideo, 'pagePath'>,
    from: Element | null = null,
): void {
    video = { ...next, pagePath: currentPagePath() };
    playedFrom = from;
    claimedSlot = null;
    heldSlot = null;
    isFloating = false;
    isPopOutRequested = false;
    playingInPlace = null;
    notify();
}

export function closeVideo(): void {
    video = null;
    playedFrom = null;
    claimedSlot = null;
    heldSlot = null;
    isFloating = false;
    isPopOutRequested = false;
    notify();
}

/**
 * An embed that can't hand its video to the floating player, like one in a chat, plays it in place, and stops
 * whichever video was playing before, floating or in place.
 */
export function playInPlace(owner: string): void {
    closeVideo();
    playingInPlace = owner;
    notify();
}

export function isPlayingInPlace(owner: string): boolean {
    return playingInPlace === owner;
}

export function useIsPlayingInPlace(owner: string): boolean {
    return useSyncExternalStore(
        subscribeToVideoDock,
        () => isPlayingInPlace(owner),
        () => false,
    );
}

export function takePlayedFrom(): Element | null {
    const from = playedFrom;

    playedFrom = null;

    return from;
}

/**
 * Sends the video playing in its embed to the corner of the screen, without leaving the page. Asked for anywhere but
 * in its embed, the request lapses rather than firing later.
 */
export function popOutVideo(): void {
    if (video === null) {
        return;
    }

    isPopOutRequested = true;
    notify();
}

export function takePopOutRequest(): boolean {
    const isRequested = isPopOutRequested;

    isPopOutRequested = false;

    return isRequested;
}

/**
 * The embed the player sits in, if it sits in one, so that embed can stop describing the player as elsewhere.
 */
export function setHeldSlot(element: HTMLElement | null): void {
    const next =
        element === null
            ? null
            : (slots.find((slot) => slot.element === element) ?? null);

    if (heldSlot !== next) {
        heldSlot = next;
        notify();
    }
}

/**
 * Whether the player floats in a corner. Only then is the video somewhere else for its embeds to point to: while it
 * is on its way into an embed, or about to appear in one, they simply show the video's poster.
 */
export function setVideoFloating(next: boolean): void {
    if (isFloating !== next) {
        isFloating = next;
        notify();
    }
}

export function isVideoFloating(): boolean {
    return isFloating;
}

export function useIsVideoFloating(): boolean {
    return useSyncExternalStore(
        subscribeToVideoDock,
        isVideoFloating,
        () => false,
    );
}

export function heldSlotOwner(): string | null {
    return heldSlot?.owner ?? null;
}

export function useHeldSlotOwner(): string | null {
    return useSyncExternalStore(
        subscribeToVideoDock,
        heldSlotOwner,
        () => null,
    );
}

/**
 * Brings the floating video into an embed of it on this page, which it then goes back to after a visit elsewhere.
 */
export function playVideoHere(slot: HTMLElement): void {
    if (video === null) {
        return;
    }

    video = { ...video, pagePath: currentPagePath() };
    claimedSlot = slot;
    isPopOutRequested = false;
    notify();
}

export function takeClaimedSlot(): HTMLElement | null {
    const slot = claimedSlot;

    claimedSlot = null;

    return slot?.isConnected ? slot : null;
}

export function dockedVideo(): DockedVideo | null {
    return video;
}

export function useDockedVideo(): DockedVideo | null {
    return useSyncExternalStore(subscribeToVideoDock, dockedVideo, () => null);
}

export function registerVideoSlot(
    src: string,
    element: HTMLElement,
    owner: string,
): () => void {
    const slot = { src, element, owner };

    slots.push(slot);
    notify();

    return () => {
        const index = slots.indexOf(slot);

        if (index !== -1) {
            slots.splice(index, 1);
        }

        if (heldSlot === slot) {
            heldSlot = null;
        }

        notify();
    };
}

/**
 * Only an embed in the page itself, scrolled by the window, can hand its video to the floating player, which is laid
 * over the page. One in a panel that scrolls on its own or floats above the page, like a chat or the photo viewer,
 * would leave the player behind it or drifting over its edges, so it plays in place.
 */
export function canPlayFloating(element: Element): boolean {
    const main = element.closest('[data-main]');

    if (main === null || element.closest('[role="dialog"]') !== null) {
        return false;
    }

    for (
        let node = element.parentElement;
        node !== null && node !== main;
        node = node.parentElement
    ) {
        const { overflowY, position } = getComputedStyle(node);

        if (
            overflowY === 'auto' ||
            overflowY === 'scroll' ||
            position === 'fixed'
        ) {
            return false;
        }
    }

    return true;
}

/**
 * The embed of a video the player goes back to: the one inside `within` when given, otherwise the first on the page,
 * in the order the page shows them rather than the order they were added in.
 */
export function videoSlot(
    src: string,
    within: Element | null = null,
): HTMLElement | null {
    const candidates = slots
        .filter((slot) => slot.src === src && slot.element.isConnected)
        .map((slot) => slot.element);
    const inside =
        within === null
            ? []
            : candidates.filter((element) => within.contains(element));

    return (
        (inside.length > 0 ? inside : candidates).sort((first, second) =>
            first.compareDocumentPosition(second) &
            Node.DOCUMENT_POSITION_FOLLOWING
                ? -1
                : 1,
        )[0] ?? null
    );
}

export function readDockPlacement(viewport: Size): DockPlacement {
    const fallback: DockPlacement = {
        corner: defaultDockCorner(viewport),
        length: defaultDockLength(viewport),
    };

    try {
        const stored = JSON.parse(
            readStorage(storageKey(viewport)) || 'null',
        ) as Partial<DockPlacement> | null;
        return {
            corner:
                stored?.corner && corners.includes(stored.corner)
                    ? stored.corner
                    : fallback.corner,
            length:
                typeof stored?.length === 'number' &&
                Number.isFinite(stored.length)
                    ? stored.length
                    : fallback.length,
        };
    } catch {
        return fallback;
    }
}

export function writeDockPlacement(
    placement: DockPlacement,
    viewport: Size,
): void {
    writeStorage(storageKey(viewport), JSON.stringify(placement));
}
