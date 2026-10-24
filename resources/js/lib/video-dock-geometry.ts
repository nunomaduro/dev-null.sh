/**
 * Where the floating video player sits and how big it is, kept apart from the DOM so it can be reasoned about alone.
 */
export type Corner = 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right';

export const corners: Corner[] = [
    'top-left',
    'top-right',
    'bottom-left',
    'bottom-right',
];

export type Box = { x: number; y: number; width: number; height: number };

export type Size = { width: number; height: number };

export type Point = { x: number; y: number };

/**
 * Something fixed to the bottom of the viewport, like the mobile nav or the chat dock, that the player rests above.
 */
export type Obstacle = { left: number; right: number; top: number };

export type DockPlacement = { corner: Corner; length: number };

/**
 * A header stuck to the top of the page. The page scrolls beneath it, so it is where the visible page begins, and the
 * player rests below it.
 */
export type Ceiling = { left: number; right: number; bottom: number };

export const ceilingGap = 16;

/**
 * How far down the visible page starts above a horizontal span: below the lowest header stuck over it.
 */
/**
 * How far down the visible page ends below a horizontal span: above the highest bar fixed to the bottom under it.
 */
export function floorUnder(
    left: number,
    right: number,
    obstacles: Obstacle[],
    viewport: Size,
): number {
    return obstacles
        .filter((obstacle) => obstacle.left < right && obstacle.right > left)
        .reduce(
            (floor, obstacle) => Math.min(floor, obstacle.top),
            viewport.height,
        );
}

export function ceilingOver(
    left: number,
    right: number,
    ceilings: Ceiling[],
): number {
    return ceilings
        .filter((ceiling) => ceiling.left < right && ceiling.right > left)
        .reduce((top, ceiling) => Math.max(top, ceiling.bottom), 0);
}

/**
 * The resize handles curve around the player's corners from just outside it, and the grabber hangs below it, so the
 * player keeps clear of the screen's edges by enough to leave them room.
 */
export const dockMargin = 24;
export const dockGrabberSpace = 52;
export const minDockLength = 200;
export const maxDockLength = 720;

export function parseAspectRatio(aspectRatio: string | null): number | null {
    const [width, height] = (aspectRatio ?? '')
        .split('/')
        .map((side) => Number.parseFloat(side));

    return width > 0 && height > 0 ? width / height : null;
}

export function defaultDockLength(viewport: Size): number {
    return Math.round(Math.min(360, viewport.width * 0.6));
}

/**
 * Below the layout's `md` breakpoint the app is laid out for a phone: the nav sits along the bottom and a thumb does
 * the scrolling, so the player starts in the top right, out of the way of both.
 */
export const compactBelow = 768;

export function isCompact(viewport: Size): boolean {
    return viewport.width < compactBelow;
}

export function defaultDockCorner(viewport: Size): Corner {
    return isCompact(viewport) ? 'top-right' : 'bottom-right';
}

/**
 * The length is the player's longer side, so a portrait video floats as tall as a landscape one is wide.
 */
export function dockSize(
    length: number,
    aspectRatio: number,
    viewport: Size,
): Size {
    const largest = Math.min(maxDockLength, Math.max(minDockLength, length));
    const size =
        aspectRatio >= 1
            ? { width: largest, height: largest / aspectRatio }
            : { width: largest * aspectRatio, height: largest };
    const scale = Math.min(
        1,
        (viewport.width - dockMargin * 2) / size.width,
        (viewport.height - dockMargin - dockGrabberSpace) / size.height,
    );

    return {
        width: Math.round(size.width * scale),
        height: Math.round(size.height * scale),
    };
}

export function cornerBox(
    corner: Corner,
    size: Size,
    viewport: Size,
    obstacles: Obstacle[] = [],
    ceilings: Ceiling[] = [],
): Box {
    const x = corner.endsWith('left')
        ? dockMargin
        : viewport.width - dockMargin - size.width;

    if (corner.startsWith('top')) {
        const ceiling = ceilingOver(x, x + size.width, ceilings);

        return {
            x,
            y: ceiling > 0 ? ceiling + ceilingGap : dockMargin,
            ...size,
        };
    }

    const floor = obstacles
        .filter(
            (obstacle) => obstacle.left < x + size.width && obstacle.right > x,
        )
        .reduce(
            (top, obstacle) => Math.min(top, obstacle.top),
            viewport.height,
        );

    return {
        x,
        y: Math.max(dockMargin, floor - dockGrabberSpace - size.height),
        ...size,
    };
}

/**
 * A fling carries the player on for a moment before it settles, so a quick flick toward a corner lands there even when
 * it is let go of short of the middle.
 */
export function nearestCorner(
    center: Point,
    velocity: Point,
    viewport: Size,
): Corner {
    const projection = 200;
    const x = center.x + velocity.x * projection;
    const y = center.y + velocity.y * projection;

    return `${y < viewport.height / 2 ? 'top' : 'bottom'}-${x < viewport.width / 2 ? 'left' : 'right'}`;
}

/**
 * Resizing pulls one corner while the opposite one, the anchor, stays put.
 */
export function resizedLength(
    anchor: Corner,
    box: Box,
    pointer: Point,
    aspectRatio: number,
): number {
    const width = anchor.endsWith('left')
        ? pointer.x - box.x
        : box.x + box.width - pointer.x;
    const height = anchor.startsWith('top')
        ? pointer.y - box.y
        : box.y + box.height - pointer.y;

    return aspectRatio >= 1
        ? Math.max(width, height * aspectRatio)
        : Math.max(height, width / aspectRatio);
}

export function oppositeCorner(corner: Corner): Corner {
    const [vertical, horizontal] = corner.split('-');

    return `${vertical === 'top' ? 'bottom' : 'top'}-${horizontal === 'left' ? 'right' : 'left'}`;
}

/**
 * A playing video that has been in full view floats as soon as a quarter of its embed scrolls away, so it peels off
 * its post while most of it still shows, and comes back once nearly all of it shows again. The gap between the two
 * keeps it from flickering in and out around one line.
 */
export const popOutBelow = 0.75;
export const returnAbove = 0.9;

/**
 * Under this, in full view before or not, a playing video floats, and closing it doesn't hand it back: it is all but
 * gone from its embed.
 */
export const lastSliver = 0.25;

/**
 * Closing the floating player hands the video back to the embed it came from when enough of it is on screen to watch
 * there, however the video got out, and stops it when the embed is gone or scrolled away.
 */
export function closeAction(embedFraction: number | null): 'return' | 'stop' {
    return embedFraction !== null && embedFraction >= lastSliver
        ? 'return'
        : 'stop';
}

/**
 * Only a video that floated by itself goes back to its embed by itself; one popped out by hand stays out until it is
 * asked back with Play here.
 */
export function returnsByItself(
    isAutomatic: boolean,
    wasPoppedOutByHand: boolean,
): boolean {
    return isAutomatic && !wasPoppedOutByHand;
}

/**
 * Once the embed a video was in has left the page, and hasn't come back into view, a playing video floats on if
 * picture-in-picture is automatic. Otherwise, a paused or finished one included, it goes back to its embed when the
 * page still has one, and stops when it doesn't: there is nothing to keep watching.
 */
export function afterEmbedLeft(
    isAutomatic: boolean,
    isPaused: boolean,
    hasHomeEmbed: boolean,
): 'float' | 'return' | 'stop' {
    if (isAutomatic && !isPaused) {
        return 'float';
    }

    return hasHomeEmbed ? 'return' : 'stop';
}

/**
 * Where a video scrolled past sets off from: no further out than the sliver of it last seen at the edge it left by, so
 * it slides back in from that edge, rather than from somewhere well out of sight. Under a header, that edge is the
 * header's bottom.
 */
export function departureBox(rect: Box, viewport: Size, top = 0): Box {
    const sliver = rect.height * lastSliver;

    return {
        ...rect,
        y: Math.min(
            Math.max(rect.y, top + sliver - rect.height),
            viewport.height - sliver,
        ),
    };
}

/**
 * How much of an embed shows, counting what is under a header stuck above it, or behind a bar fixed below it, as
 * hidden. An embed taller than the room between them counts as all showing once it fills that room, so a tall video
 * can still come back.
 */
export function visibleFraction(
    rect: { top: number; height: number },
    viewport: Size,
    top = 0,
    bottom = viewport.height,
): number {
    if (rect.height <= 0) {
        return 0;
    }

    const visible =
        Math.min(rect.top + rect.height, bottom) - Math.max(rect.top, top);

    return Math.min(
        1,
        Math.max(0, visible) / Math.min(rect.height, Math.max(1, bottom - top)),
    );
}

/**
 * The player moves on a spring that chases its target wherever it is this frame, so it lands exactly on an embed that
 * is still scrolling, and turns around mid-flight without a jolt when the target changes.
 */
export type Spring = { position: Box; velocity: Box };

export const restingVelocity: Box = { x: 0, y: 0, width: 0, height: 0 };

const stiffness = 130;
const springStep = 1 / 240;
const sides = ['x', 'y', 'width', 'height'] as const;

/**
 * Slightly under critically damped, so the player overshoots a touch and settles, as a spring does.
 */
const dampingRatio = 0.8;

/**
 * Homing in on an embed, the pull grows as the gap closes, up to this many times as strong within this many pixels,
 * so the last stretch is closed firmly instead of crept up on.
 */
export const homingBoost = 4;
export const homingRange = 80;

export function springStiffness(gap: number, isHoming: boolean): number {
    if (!isHoming) {
        return stiffness;
    }

    const closeness = Math.max(0, 1 - Math.abs(gap) / homingRange);

    return stiffness * (1 + homingBoost * closeness);
}

export function stepSpring(
    spring: Spring,
    target: Box,
    seconds: number,
    isHoming = false,
): Spring {
    const position = { ...spring.position };
    const velocity = { ...spring.velocity };
    const steps = Math.max(1, Math.ceil(seconds / springStep));
    const step = seconds / steps;

    for (let index = 0; index < steps; index++) {
        for (const side of sides) {
            const gap = position[side] - target[side];
            const pull = springStiffness(gap, isHoming);
            const acceleration =
                -pull * gap -
                2 * dampingRatio * Math.sqrt(pull) * velocity[side];

            velocity[side] += acceleration * step;
            position[side] += velocity[side] * step;
        }
    }

    return { position, velocity };
}

/**
 * Carries the player along with a target that moved, like an embed being scrolled, so the spring only has the gap
 * between them to close and scrolling can never leave it behind.
 */
export function carriedBy(position: Box, from: Box, to: Box): Box {
    return {
        ...position,
        x: position.x + to.x - from.x,
        y: position.y + to.y - from.y,
    };
}

export function isSpringSettled(spring: Spring, target: Box): boolean {
    return sides.every(
        (side) =>
            Math.abs(spring.position[side] - target[side]) < 0.5 &&
            Math.abs(spring.velocity[side]) < 20,
    );
}
