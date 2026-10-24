import type { LiveStream } from '@/types';

// Only type imports from `@/` here: `tests/Unit/*.test.ts` load this file straight from node, which cannot resolve them.

/**
 * The people still live at `now`: drops anyone whose `live.ends_at` is at or before `now` and keeps the server's
 * order (soonest-ending first), so the rail empties itself between polls without a reload.
 */
export function visibleLive<T extends { live: Pick<LiveStream, 'ends_at'> }>(
    users: readonly T[],
    now: Date,
): T[] {
    const time = now.getTime();

    return users.filter((user) => new Date(user.live.ends_at).getTime() > time);
}

/**
 * The roving tabindex target for a key pressed on the item at `current` out of `count` items: ArrowRight moves to
 * the next and ArrowLeft to the previous, both wrapping around the ends, Home goes to the first and End to the
 * last. Returns null for any other key, or when there are no items, so the caller leaves the event alone.
 */
export function nextIndex(
    current: number,
    key: string,
    count: number,
): number | null {
    if (count === 0) {
        return null;
    }

    switch (key) {
        case 'ArrowRight':
            return (current + 1) % count;
        case 'ArrowLeft':
            return (current - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

export type CardPlacement = {
    /** The card's width: 520px, or the column less its gutters when that is narrower. */
    width: number;
    /** How far the card's left edge sits from the slot's left edge, for the popover's `alignOffset`. */
    alignOffset: number;
    /** The slot's centre measured from the card's left edge, where the bloom starts and the lane line lands. */
    originX: number;
};

/**
 * Where the stream card sits under a rail slot, from the slot's and the feed column's horizontal extents in viewport
 * pixels: it leans left of the ring by `lead` so the ring lands near the card's start, and is clamped inside the
 * column with a `gutter` on both sides, so it floats over the feed without ever leaving it.
 */
export function cardPlacement({
    slot,
    column,
    gutter = 16,
    maxWidth = 520,
    lead = 64,
}: {
    slot: { left: number; width: number };
    column: { left: number; width: number };
    gutter?: number;
    maxWidth?: number;
    lead?: number;
}): CardPlacement {
    const width = Math.max(0, Math.min(maxWidth, column.width - gutter * 2));
    const centre = slot.left + slot.width / 2;
    const min = column.left + gutter;
    const max = Math.max(min, column.left + column.width - gutter - width);
    const left = Math.min(Math.max(centre - lead, min), max);

    return {
        width,
        alignOffset: left - slot.left,
        // A slot scrolled out of the rail still points its line at the card's nearest corner, not past it.
        originX: Math.min(Math.max(centre - left, 8), Math.max(8, width - 8)),
    };
}
