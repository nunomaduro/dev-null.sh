import { useState } from 'react';
import type { ComponentProps, CSSProperties, ReactNode } from 'react';
import {
    GraphAnchor,
    WithoutGraph,
    graphDash,
    useGraphOverlay,
    useShowsUpLive,
} from '@/components/graph-canvas';
import type { GraphNodeSpec } from '@/components/graph-canvas';
import { LiveAvatar, LiveAvatarBadge } from '@/components/live/live-avatar';
import { UserAvatar } from '@/components/user-avatar';
import { bridgeDuration } from '@/lib/graph';
import { cn, laneStrokeStyle } from '@/lib/utils';
import type { User } from '@/types';

type Props = {
    lane: string;
    previousLane?: string;
    variant?: 'commit' | 'staged' | 'root';
    isFilled?: boolean;
    isFresh?: boolean;
    hasTail?: boolean;
    hasReplyTail?: boolean;
    endsWithDot?: boolean;
    isDimmed?: boolean;
    isPreviousDimmed?: boolean;
    node?: ReactNode;
    nodePosition?: 'top' | 'center' | 'bottom' | 'author';
    onHoverChange?: (isHovering: boolean) => void;
    /** The node's place in a graph canvas, which then draws the lines instead of the rail. */
    graph?: {
        id: string;
        after?: string;
        endId?: string;
        ring?: number;
        /** False when the node registers itself, because it moves inside the rail. */
        measuresNode?: boolean;
    };
};

const dimmable = 'transition-opacity duration-200';

const stagedDash = 4;

/**
 * A fresh commit's end dot pops once its avatar has scaled in (700ms) and its line looks drawn: the draw eases out, so
 * the line reads as whole well before its 500ms end.
 */
const endDotDelay = 900;

/** A rail dot's ring is a 2px box-shadow, outside its box. */
export const railDotRing = 2;

// Nodes fade inside an opaque backdrop so the lane line behind them stays hidden.
const dimmedNode = 'group-data-dimmed/rail-node:opacity-60';

export function GraphRail({
    lane,
    previousLane,
    variant = 'commit',
    isFilled = true,
    isFresh = false,
    hasTail = true,
    hasReplyTail = false,
    endsWithDot = false,
    isDimmed = false,
    isPreviousDimmed = false,
    node,
    nodePosition = 'top',
    onHoverChange,
    graph,
}: Props) {
    // A fresh commit going on below another one, like a reply chained under the last commit of a thread, scales in once
    // the trunk has reached it.
    const waitsForTrunk = isFresh && previousLane !== undefined;

    return (
        <div
            aria-hidden
            className={cn('relative min-h-16 w-16 shrink-0 sm:w-18', {
                'min-h-24': endsWithDot || nodePosition === 'bottom',
            })}
            style={{ '--lane': lane } as CSSProperties}
            onPointerEnter={
                onHoverChange &&
                ((event) =>
                    event.pointerType === 'mouse' && onHoverChange(true))
            }
            onPointerLeave={
                onHoverChange &&
                ((event) =>
                    event.pointerType === 'mouse' && onHoverChange(false))
            }
        >
            <WithoutGraph>
                {previousLane && variant === 'staged' && (
                    <span className="absolute top-0 left-1/2 h-8 -translate-x-1/2 border-l-2 border-dashed border-rail" />
                )}

                {previousLane && variant !== 'staged' && (
                    <span
                        className={cn(
                            // Overlap the tail by 1px so abutting edges don't flash a hairline gap.
                            'absolute top-0 left-1/2 h-[calc(2rem+1px)] w-0.5 -translate-x-1/2',
                            dimmable,
                            {
                                'opacity-60': isPreviousDimmed,
                                'h-[calc(50%+1px)]': nodePosition === 'center',
                                'h-6': nodePosition === 'author',
                                'bottom-5 h-auto': nodePosition === 'bottom',
                            },
                        )}
                        style={{ background: previousLane }}
                        data-test="rail-previous"
                    />
                )}

                {hasTail &&
                    (variant === 'staged' ? (
                        <span
                            className="absolute top-8 bottom-0 left-1/2 w-0.5 -translate-x-1/2"
                            style={laneStrokeStyle('var(--rail)', {
                                dash: stagedDash,
                                anchor: 'end',
                            })}
                            data-test="rail-staged-tail"
                        />
                    ) : (
                        <span
                            className={cn(
                                // Start 1px early so this meets the previous segment without a seam.
                                'absolute top-[calc(2rem-1px)] bottom-0 left-1/2 w-0.5 -translate-x-1/2 bg-(--lane)',
                                dimmable,
                                {
                                    'opacity-60': isDimmed,
                                    'bottom-5': endsWithDot,
                                    'top-[calc(50%-1px)]':
                                        nodePosition === 'center',
                                    'top-[22px]': nodePosition === 'author',
                                },
                            )}
                            data-test="rail-tail"
                        />
                    ))}

                {hasReplyTail && (
                    <span
                        className={cn(
                            'absolute top-8 bottom-0 left-1/2 w-0.5 -translate-x-1/2',
                            dimmable,
                            {
                                'opacity-60': isDimmed,
                                'top-[23px]': nodePosition === 'author',
                                'top-1/2': nodePosition === 'center',
                                'top-auto h-5':
                                    endsWithDot || nodePosition === 'bottom',
                            },
                        )}
                        style={laneStrokeStyle('var(--lane)', {
                            dash: graphDash,
                        })}
                        data-test="commit-reply-spine"
                    />
                )}
            </WithoutGraph>

            <RailEnd
                isShown={hasTail && endsWithDot}
                spec={
                    graph?.endId
                        ? {
                              id: graph.endId,
                              lane,
                              after: graph.id,
                              dimmed: isDimmed,
                              ring: railDotRing,
                          }
                        : null
                }
                isFresh={isFresh}
                isDimmed={isDimmed}
                delay={endDotDelay + (waitsForTrunk ? bridgeDuration : 0)}
            />

            <GraphAnchor
                spec={
                    graph && graph.measuresNode !== false
                        ? {
                              id: graph.id,
                              lane,
                              after: graph.after,
                              dimmed: isDimmed,
                              ring: graph.ring,
                          }
                        : null
                }
                data-dimmed={isDimmed || undefined}
                isFresh={isFresh}
                style={
                    waitsForTrunk
                        ? { animationDelay: `${bridgeDuration}ms` }
                        : undefined
                }
                className={cn(
                    'group/rail-node absolute top-8 left-1/2 flex -translate-1/2 rounded-full',
                    {
                        'top-1/2': nodePosition === 'center',
                        'top-[23px]': nodePosition === 'author',
                        'top-auto bottom-5 translate-y-1/2':
                            nodePosition === 'bottom',
                    },
                )}
            >
                {node ?? <RailDot isHollow={!isFilled || variant === 'root'} />}
            </GraphAnchor>
        </div>
    );
}

// Without motion, no animation would end to take a leaving end dot away.
function prefersStillness(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Where a thread ends, when it ends on this commit. Once the thread goes on below it, live, the end dot scales out, the
 * way it scaled in, as the trunk runs on through it.
 */
function RailEnd({
    isShown,
    ...dot
}: { isShown: boolean } & Omit<
    ComponentProps<typeof RailEndDot>,
    'isLeaving' | 'onLeft'
>) {
    const isDrawing = useGraphOverlay();
    const [wasShown, setWasShown] = useState(isShown);
    const [isLeaving, setIsLeaving] = useState(false);

    if (wasShown !== isShown) {
        setWasShown(isShown);
        setIsLeaving(!isShown && isDrawing && !prefersStillness());
    }

    return isShown || isLeaving ? (
        <RailEndDot
            {...dot}
            isLeaving={!isShown}
            onLeft={() => setIsLeaving(false)}
        />
    ) : null;
}

/**
 * The dot a thread ends on. A fresh one pops once its line is drawn, so the line doesn't wait for it. A leaving one, as
 * the thread goes on below it, scales out the way it scaled in, and is gone once it has. It stays a node of the canvas,
 * linked to nothing, whose cut-out scales out with it: the trunk running on through it never shows across it.
 */
export function RailEndDot({
    spec = null,
    isFresh = false,
    isDimmed = false,
    delay = endDotDelay,
    isLeaving = false,
    onLeft,
}: {
    spec?: GraphNodeSpec | null;
    isFresh?: boolean;
    isDimmed?: boolean;
    delay?: number;
    isLeaving?: boolean;
    onLeft?: () => void;
}) {
    return (
        <GraphAnchor
            spec={
                isLeaving && spec
                    ? {
                          id: spec.id,
                          lane: spec.lane,
                          ring: spec.ring,
                          isLeaving: true,
                      }
                    : spec
            }
            data-dimmed={isDimmed || undefined}
            isFresh={isFresh && !isLeaving}
            className={cn(
                'group/rail-node absolute bottom-5 left-1/2 flex -translate-x-1/2 translate-y-1/2 rounded-full',
                { 'motion-safe:animate-node-out': isLeaving },
            )}
            style={
                isLeaving
                    ? // Gone as the trunk reaches the commit going on below it, which then scales in.
                      { animationDuration: `${bridgeDuration}ms` }
                    : isFresh
                      ? { animationDelay: `${delay}ms` }
                      : undefined
            }
            onAnimationEnd={(event) => {
                if (isLeaving && event.target === event.currentTarget) {
                    onLeft?.();
                }
            }}
            data-graph-trails
            data-graph-leaves={isLeaving || undefined}
            data-test="rail-end-dot"
        >
            <RailDot />
        </GraphAnchor>
    );
}

/**
 * The body of a commit. A fresh one going on below another, like a reply chained under the last commit of a thread,
 * fades in as the trunk draws itself to it, and shows in full as its node scales in.
 */
export function ChainedEntrance({
    isFresh = false,
    previousLane,
    className,
    children,
}: {
    isFresh?: boolean;
    previousLane?: string;
    className?: string;
    children: ReactNode;
}) {
    const isLive = useShowsUpLive() && isFresh && previousLane !== undefined;

    return (
        <div
            className={cn(className, {
                'motion-safe:animate-commit-in': isLive,
            })}
            style={
                isLive
                    ? { animationDuration: `${bridgeDuration}ms` }
                    : undefined
            }
        >
            {children}
        </div>
    );
}

export function RailDot({
    isHollow = false,
    className,
}: {
    isHollow?: boolean;
    className?: string;
}) {
    return (
        <span className="flex rounded-full bg-card">
            <span
                className={cn(
                    'size-3.5 rounded-full border-[3px] border-card bg-(--lane) shadow-[0_0_0_2px_var(--lane)] transition-[background-color,scale,opacity] duration-200',
                    dimmedNode,
                    { 'bg-card': isHollow },
                    className,
                )}
            />
        </span>
    );
}

export function RailAvatar({
    user,
    progress,
}: {
    user: Pick<User, 'name' | 'avatar_url' | 'lane' | 'live'>;
    // Fills the ring clockwise from 0 to 1, as a post grows toward its limit. At 0 the ring stays dashed.
    progress?: number;
}) {
    const isLive = Boolean(user.live);

    if (isLive && progress === undefined) {
        return (
            <span className="relative flex rounded-full bg-card">
                <span
                    className={cn(
                        'flex transition-opacity duration-200',
                        dimmedNode,
                    )}
                >
                    <LiveAvatar user={user} size={40} />
                </span>
            </span>
        );
    }

    return (
        <span className="relative flex rounded-full bg-card">
            <span
                className={cn(
                    'relative flex rounded-full border-2 border-(--lane) p-0.5 transition-opacity duration-200',
                    dimmedNode,
                    { 'border-transparent': progress !== undefined },
                )}
            >
                {progress !== undefined && (
                    <>
                        <span
                            className={cn(
                                'pointer-events-none absolute -inset-0.5 rounded-full border-2 border-dashed border-(--lane) transition-opacity duration-150 ease-out motion-reduce:transition-none',
                                progress > 0 ? 'opacity-0' : 'opacity-100',
                            )}
                        />
                        <svg
                            viewBox="0 0 48 48"
                            className={cn(
                                'pointer-events-none absolute -inset-0.5 size-12 -rotate-90 transition-opacity duration-150 ease-out motion-reduce:transition-none',
                                progress > 0 ? 'opacity-100' : 'opacity-0',
                            )}
                            fill="none"
                            strokeWidth={2}
                        >
                            <circle
                                cx="24"
                                cy="24"
                                r="23"
                                className="stroke-(--lane) opacity-25"
                            />
                            <circle
                                cx="24"
                                cy="24"
                                r="23"
                                pathLength={1}
                                strokeDasharray={1}
                                strokeLinecap="round"
                                strokeDashoffset={1 - Math.min(progress, 1)}
                                className="stroke-(--lane) transition-[stroke-dashoffset] duration-150 ease-out motion-reduce:transition-none"
                            />
                        </svg>
                    </>
                )}
                <UserAvatar user={user} className="size-10" />
            </span>
            {/* The staged composer node keeps its progress ring while you stream, so only the pill says live. */}
            {isLive && <LiveAvatarBadge size={40} />}
        </span>
    );
}

export function RailSegment({
    lane,
    isDimmed = false,
    isDashed = false,
    className,
}: {
    lane?: string;
    isDimmed?: boolean;
    isDashed?: boolean;
    className?: string;
}) {
    return (
        <div
            aria-hidden
            className={cn('relative w-16 shrink-0 sm:w-18', className)}
        >
            {lane &&
                (isDashed ? (
                    // The dashed rail carries the composer's on, which no graph canvas draws.
                    <span
                        className="absolute inset-y-0 left-1/2 w-0.5 -translate-x-1/2"
                        style={laneStrokeStyle(lane, { dash: stagedDash })}
                    />
                ) : (
                    <WithoutGraph>
                        <span
                            className={cn(
                                'absolute inset-y-0 left-1/2 w-0.5 -translate-x-1/2',
                                dimmable,
                                { 'opacity-60': isDimmed },
                            )}
                            style={{ background: lane }}
                        />
                    </WithoutGraph>
                ))}
        </div>
    );
}
