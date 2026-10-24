import {
    ceilingOver,
    floorUnder,
    visibleFraction,
} from '@/lib/video-dock-geometry';
import type { Box, Ceiling, Obstacle, Size } from '@/lib/video-dock-geometry';

/**
 * What the floating player reads off the page. Each frame asks for the same things several times, so they are read
 * once a frame and kept until the next one starts.
 */
const measured = new Map<string, unknown>();

export function startFrame(): void {
    measured.clear();
}

function once<T>(key: string, read: () => T): T {
    if (!measured.has(key)) {
        measured.set(key, read());
    }

    return measured.get(key) as T;
}

export function viewport(): Size {
    return once('viewport', () => ({
        width: document.documentElement.clientWidth,
        height: window.innerHeight,
    }));
}

export function obstacles(): Obstacle[] {
    return once('obstacles', () =>
        Array.from(
            document.querySelectorAll<HTMLElement>(
                '[data-video-dock-obstacle]',
            ),
        )
            .map((element) => element.getBoundingClientRect())
            .filter((rect) => rect.width > 0 && rect.height > 0)
            .map(({ left, right, top }) => ({ left, right, top })),
    );
}

/**
 * The headers stuck to the top of the page right now. Every page keeps its own sticky header in the main column, so
 * they are found as they stand rather than each being marked.
 */
export function ceilings(): Ceiling[] {
    return once('ceilings', () =>
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-main] .sticky'),
        )
            .filter(
                (element) => getComputedStyle(element).position === 'sticky',
            )
            .map((element) => element.getBoundingClientRect())
            .filter(
                (rect) => rect.width > 0 && rect.height > 0 && rect.top <= 1,
            )
            .map(({ left, right, bottom }) => ({ left, right, bottom })),
    );
}

export function ceilingAbove(box: Box): number {
    return ceilingOver(box.x, box.x + box.width, ceilings());
}

export function floorBelow(box: Box): number {
    return floorUnder(box.x, box.x + box.width, obstacles(), viewport());
}

export function toBox(rect: DOMRect): Box {
    return {
        x: rect.left,
        y: rect.top,
        width: rect.width,
        height: rect.height,
    };
}

/**
 * How much of an embed the reader can see, leaving out what has scrolled under a header.
 */
export function shownFraction(rect: DOMRect): number {
    const box = toBox(rect);

    return visibleFraction(
        rect,
        viewport(),
        ceilingAbove(box),
        floorBelow(box),
    );
}

export function isSameBox(first: Box, second: Box): boolean {
    return (
        Math.abs(first.x - second.x) < 0.5 &&
        Math.abs(first.y - second.y) < 0.5 &&
        Math.abs(first.width - second.width) < 0.5 &&
        Math.abs(first.height - second.height) < 0.5
    );
}

export function prefersReducedMotion(): boolean {
    return once(
        'reduced-motion',
        () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );
}

/**
 * Laid over the embed, the player is positioned against the document rather than the viewport, so it scrolls with the
 * page natively instead of trailing it by a frame.
 */
export function documentBox(slot: HTMLElement): Box {
    const rect = slot.getBoundingClientRect();
    const origin = once('origin', () =>
        (getComputedStyle(document.body).position === 'static'
            ? document.documentElement
            : document.body
        ).getBoundingClientRect(),
    );

    return {
        x: rect.left - origin.left,
        y: rect.top - origin.top,
        width: rect.width,
        height: rect.height,
    };
}

/**
 * The embed's card rounds its top corners, so the player does too while it sits in them.
 */
export function slotRadius(slot: HTMLElement): string {
    const card = slot.closest<HTMLElement>('[data-test="link-embed"]');

    if (card === null) {
        return '0px';
    }

    const style = getComputedStyle(card);
    const border = Number.parseFloat(style.borderTopWidth) || 0;
    const radius = Math.max(
        0,
        (Number.parseFloat(style.borderTopLeftRadius) || 0) - border,
    );
    const slotRect = slot.getBoundingClientRect();
    const cardRect = card.getBoundingClientRect();
    const isInCorners =
        Math.abs(slotRect.top - cardRect.top - border) < 1 &&
        Math.abs(slotRect.width - (cardRect.width - border * 2)) < 1;

    return isInCorners ? `${radius}px ${radius}px 0 0` : '0px';
}

/**
 * Calls back whenever something the resting player's corner depends on may have moved: a scroll can stick or unstick
 * a header, a resize or a visit can change what sits at the edges, and the chat dock grows and shrinks in place. Bars
 * that appear later, like a chat docked after the video started, are picked up as they arrive.
 */
export function watchCornerChanges(onChange: () => void): () => void {
    const resizes = new ResizeObserver(onChange);
    const watched = new Set<Element>();
    const watchNew = () => {
        watched.forEach((element) => {
            if (!element.isConnected) {
                watched.delete(element);
                resizes.unobserve(element);
            }
        });
        document
            .querySelectorAll('[data-video-dock-obstacle], [data-main]')
            .forEach((element) => {
                if (!watched.has(element)) {
                    watched.add(element);
                    resizes.observe(element);
                    onChange();
                }
            });
    };
    const arrivals = new MutationObserver(watchNew);

    watchNew();
    arrivals.observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize', onChange);
    window.addEventListener('scroll', onChange, { passive: true });

    return () => {
        resizes.disconnect();
        arrivals.disconnect();
        window.removeEventListener('resize', onChange);
        window.removeEventListener('scroll', onChange);
    };
}
