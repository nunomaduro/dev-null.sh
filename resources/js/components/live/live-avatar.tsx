import type { CSSProperties, ReactNode } from 'react';
import { LiveBadge } from '@/components/live-badge';
import { UserAvatar } from '@/components/user-avatar';
import { cn, laneColor } from '@/lib/utils';
import type { Lane, User } from '@/types';

// The lanes in the order the ring passes them, so neighbours never share a hue family.
const ringOrder = [0, 5, 1, 3, 2, 4];

/**
 * The person's own lane holds the first fifth of the ring, then the other lanes follow it round, back into theirs.
 * A custom color leads the six palette lanes the same way.
 */
function ringGradient(lane: Lane): string {
    const start = typeof lane === 'number' ? ringOrder.indexOf(lane) : -1;
    const others =
        start === -1
            ? ringOrder
            : [...ringOrder.slice(start + 1), ...ringOrder.slice(0, start)];
    const color = laneColor(lane);
    const stops = others.map(
        (other, index) => `var(--lane-${other}) ${110 + index * 50}deg`,
    );

    return `conic-gradient(${[`${color} 0deg 70deg`, ...stops, `${color} 360deg`].join(', ')})`;
}

/**
 * The live look for anything round: a rotating conic ring in the lane's color with a gap before `children`. It
 * holds still under reduced motion. The gap is see-through, so it sits on any surface, and the ring only ever
 * rotates, which the compositor does without repainting. `isGlowing` adds a soft halo for the one that is selected.
 */
function LiveRing({
    lane,
    isGlowing = false,
    children,
}: {
    lane: Lane;
    isGlowing?: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className="relative flex shrink-0 rounded-full p-1"
            style={{ '--live-ring': ringGradient(lane) } as CSSProperties}
            data-test="live-ring"
        >
            {isGlowing && (
                <span
                    aria-hidden
                    className="pointer-events-none absolute -inset-0.5 rounded-full bg-(image:--live-ring) opacity-50 blur-md motion-safe:animate-live-ring"
                />
            )}
            <span
                aria-hidden
                className="pointer-events-none absolute inset-0 live-ring motion-safe:animate-live-ring"
            />
            <span className="relative flex rounded-full">{children}</span>
        </span>
    );
}

let offscreen: IntersectionObserver | undefined;

/**
 * Marks a live avatar `data-offscreen` while it is out of view, through one observer for the whole page. Chrome can't
 * hand an unpainted element's animation to the compositor, so a ring spinning below the fold would otherwise
 * restyle the page on the main thread every frame.
 */
function observeOffscreen(element: HTMLElement): () => void {
    offscreen ??= new IntersectionObserver((entries) => {
        for (const entry of entries) {
            entry.target.toggleAttribute(
                'data-offscreen',
                !entry.isIntersecting,
            );
        }
    });
    offscreen.observe(element);

    return () => offscreen?.unobserve(element);
}

const sizes = {
    28: { avatar: 'size-7', badge: 'px-1 text-[8px] leading-3' },
    36: { avatar: 'size-9', badge: 'px-1 text-[8px] leading-3' },
    40: { avatar: 'size-10', badge: 'px-1 text-[9px] leading-3.5' },
    56: { avatar: 'size-14 text-lg', badge: '' },
    80: { avatar: 'size-20 text-2xl', badge: '' },
} as const;

/**
 * The LIVE pill on the bottom edge of a round avatar at `size`, cut out of whatever ring it overlaps in the `surface`
 * it sits on. Place it inside a `relative` box the avatar's size; `LiveAvatar` already carries it.
 */
export function LiveAvatarBadge({
    size,
    surface = 'card',
}: {
    size: keyof typeof sizes;
    surface?: 'card' | 'popover';
}) {
    return (
        <LiveBadge
            className={cn(
                'absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2 ring-2',
                surface === 'card' ? 'ring-card' : 'ring-popover',
                sizes[size].badge,
            )}
            // On a feed node the pill hangs past the ring, so the graph's lines pass behind it too.
            data-graph-cutout
        />
    );
}

/**
 * A user's avatar inside a `LiveRing` with the LIVE pill on the ring's bottom edge, at the avatar's `size` in px.
 * `active` marks the one whose stream card is open.
 */
export function LiveAvatar({
    user,
    size,
    active = false,
    surface = 'card',
}: {
    user: Pick<User, 'name' | 'avatar_url' | 'lane'>;
    size: keyof typeof sizes;
    active?: boolean;
    surface?: 'card' | 'popover';
}) {
    return (
        <span
            ref={observeOffscreen}
            className="relative flex shrink-0 data-offscreen:**:[animation-play-state:paused]"
            data-test="live-avatar"
        >
            <LiveRing lane={user.lane} isGlowing={active}>
                <UserAvatar
                    user={user}
                    className={sizes[size].avatar}
                    pixels={size * 2}
                />
            </LiveRing>
            <LiveAvatarBadge size={size} surface={surface} />
        </span>
    );
}
