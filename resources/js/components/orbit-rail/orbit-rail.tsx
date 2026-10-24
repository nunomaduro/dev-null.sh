import { Link, usePage, usePoll } from '@inertiajs/react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Square } from 'lucide-react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { GoLiveDialog, useEndStream } from '@/components/go-live-dialog';
import { LiveStreamSheet } from '@/components/live-stream-card';
import { LivePreviewCard } from '@/components/live/live-preview-card';
import { OrbitRailCard } from '@/components/orbit-rail/orbit-rail-card';
import type { OrbitRailSlotProps } from '@/components/orbit-rail/orbit-rail-item';
import { OrbitRailItem } from '@/components/orbit-rail/orbit-rail-item';
import { OrbitRailYou } from '@/components/orbit-rail/orbit-rail-you';
import { Button } from '@/components/ui/button';
import { useHoverIntent } from '@/hooks/use-hover-intent';
import { useMountAnimations } from '@/hooks/use-mount-animations';
import { useNow } from '@/hooks/use-now';
import { backgroundPollOptions } from '@/lib/background-poll';
import { isStreaming } from '@/lib/live';
import type { CardPlacement } from '@/lib/orbit-rail';
import { cardPlacement, nextIndex, visibleLive } from '@/lib/orbit-rail';
import { laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { LiveFollowingUser, LiveStream, Profile } from '@/types';

/** The You slot's id among the people's ids; user ids are UUIDs, so it never collides. */
const you = 'you';

/** Until the rail is measured, the card sits where the first ring's would. */
const fallbackPlacement: CardPlacement = {
    width: 520,
    alignOffset: 0,
    originX: 28,
};

type OpenCard = {
    id: string;
    /** Pinned by a click, Enter or play: hovering away no longer closes it. */
    pinned: boolean;
    /** Bumped by each click or Enter, which moves focus into the card; null when hover opened it. */
    focusRequest: number | null;
    placement: CardPlacement;
};

type SlotHandlers = Omit<OrbitRailSlotProps, 'isActive' | 'tabIndex'>;

/**
 * The live-now rail between the log tabs and the composer: you first, then up to 24 people you follow from the
 * shared `liveFollowing` prop, soonest-ending first, with one floating `LivePreviewCard` for whichever is open.
 * Signed-in only, and a fixed 84px tall so it never shifts the feed.
 */
export function OrbitRail() {
    const {
        auth: { user },
        liveFollowing = [],
    } = usePage().props;

    if (user === null) {
        return null;
    }

    return <Rail me={user} liveFollowing={liveFollowing} />;
}

function Rail({
    me,
    liveFollowing,
}: {
    me: Profile;
    liveFollowing: LiveFollowingUser[];
}) {
    // The rail refreshes itself, so ranked logs, whose page poll never starts, still see people go live and stop.
    usePoll(
        30_000,
        backgroundPollOptions({ only: ['liveFollowing'], preserveUrl: true }),
        { mode: 'rest', background: 'pause' },
    );

    const now = useNow();
    const time = now.getTime();
    const people = useMemo(
        () => visibleLive(liveFollowing, new Date(time)),
        [liveFollowing, time],
    );
    const myStream: LiveStream | null =
        me.live && isStreaming(me.live, now) ? me.live : null;
    // Keyed by who is on the rail, not by the array, so a poll or a clock tick with the same people keeps the slots'
    // handlers, and with them the slots, as they are.
    const peopleKey = people.map((person) => person.id).join(' ');
    const ids = useMemo(
        () => [you, ...(peopleKey === '' ? [] : peopleKey.split(' '))],
        [peopleKey],
    );

    const section = useRef<HTMLElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const [slots] = useState(() => new Map<string, HTMLButtonElement>());
    const [card, setCard] = useState<OpenCard | null>(null);
    const [sheet, setSheet] = useState<{ id: string; open: boolean } | null>(
        null,
    );
    const [isGoingLive, setIsGoingLive] = useState(false);
    const [focusIndex, setFocusIndex] = useState(0);
    const [edges, setEdges] = useState({ start: true, end: true });
    const hovered = useRef<string | null>(null);
    const isPlaying = useRef(false);
    const restoreFocus = useRef<HTMLElement | null>(null);

    const openPerson = card && people.find((person) => person.id === card.id);
    const isOpen =
        card !== null &&
        (card.id === you ? myStream !== null : openPerson !== undefined);

    // Whoever's card was open stopped streaming, or dropped off the rail with the last poll.
    if (card !== null && !isOpen) {
        setCard(null);
    }

    const rovingIndex = Math.min(focusIndex, ids.length - 1);
    const activeId = isOpen
        ? card.id
        : sheet?.open
          ? sheet.id
          : isGoingLive
            ? you
            : null;

    const place = useCallback(
        (id: string): CardPlacement => {
            const slot = slots.get(id)?.getBoundingClientRect();
            const column = (
                section.current?.closest('[data-main]') ?? section.current
            )?.getBoundingClientRect();

            return slot && column
                ? cardPlacement({ slot, column })
                : fallbackPlacement;
        },
        [slots],
    );

    // The handlers below are created once per set of slots, so they read the latest render through this ref instead of
    // closing over it, and a poll or an opened card re-renders only the slots whose props actually changed.
    const latest = useRef({ card, people, myStream });
    useLayoutEffect(() => {
        latest.current = { card: isOpen ? card : null, people, myStream };
    });

    const canPreview = useCallback((id: string) => {
        const { people, myStream } = latest.current;

        return id === you
            ? myStream !== null
            : people.some((person) => person.id === id);
    }, []);

    const close = useCallback(() => {
        isPlaying.current = false;
        setCard(null);
    }, []);

    // Every way a card opens or moves to another ring measures where it goes against that ring.
    const openCard = useCallback(
        (id: string, card: Omit<OpenCard, 'id' | 'placement'>) =>
            setCard({ ...card, id, placement: place(id) }),
        [place],
    );

    // One hover intent for every slot and the card, so moving between them shares the open delay and the close grace.
    const onHoverChange = useCallback(
        (isHovering: boolean) => {
            if (!isHovering) {
                setCard((current) =>
                    current === null || current.pinned ? current : null,
                );

                return;
            }

            const id = hovered.current;

            if (latest.current.card === null && id && canPreview(id)) {
                openCard(id, { pinned: false, focusRequest: null });
            }
        },
        [canPreview, openCard],
    );
    const hover = useHoverIntent({ onChange: onHoverChange });
    const hoverRef = useRef(hover);
    useLayoutEffect(() => {
        hoverRef.current = hover;
    });

    const handlers = useMemo(
        () =>
            new Map(
                ids.map((id, index): [string, SlotHandlers] => [
                    id,
                    {
                        ref: (element: HTMLButtonElement) => {
                            slots.set(id, element);

                            return () => {
                                if (slots.get(id) === element) {
                                    slots.delete(id);
                                }
                            };
                        },
                        onPointerEnter: (event: PointerEvent) => {
                            if (event.pointerType !== 'mouse') {
                                return;
                            }

                            hovered.current = id;

                            if (!canPreview(id)) {
                                return;
                            }

                            // Once a card is open, the next ring takes it over at once, unless a stream is playing in it.
                            const open = latest.current.card;
                            if (
                                open !== null &&
                                open.id !== id &&
                                !isPlaying.current
                            ) {
                                openCard(id, {
                                    pinned: open.pinned,
                                    focusRequest: null,
                                });
                            }

                            hoverRef.current.onPointerEnter(event);
                        },
                        onPointerLeave: (event: PointerEvent) => {
                            if (hovered.current === id) {
                                hovered.current = null;
                            }

                            hoverRef.current.onPointerLeave(event);
                        },
                        onClick: () => {
                            hoverRef.current.cancel();

                            if (
                                id === you &&
                                latest.current.myStream === null
                            ) {
                                close();
                                setIsGoingLive(true);

                                return;
                            }

                            // Touch screens have nothing to hover, so the card opens as a sheet instead.
                            if (!window.matchMedia('(hover: hover)').matches) {
                                close();
                                setSheet({ id, open: true });

                                return;
                            }

                            const open = latest.current.card;

                            if (open?.id === id && open.pinned) {
                                close();

                                return;
                            }

                            if (open?.id !== id) {
                                isPlaying.current = false;
                            }

                            openCard(id, {
                                pinned: true,
                                focusRequest: (open?.focusRequest ?? 0) + 1,
                            });
                        },
                        onFocus: () => setFocusIndex(index),
                    },
                ]),
            ),
        [ids, slots, canPreview, close, openCard],
    );

    const syncScroll = useCallback(() => {
        const element = scroller.current;

        if (element) {
            const start = element.scrollLeft <= 1;
            const end =
                element.scrollLeft + element.clientWidth >=
                element.scrollWidth - 1;
            setEdges((current) =>
                current.start === start && current.end === end
                    ? current
                    : { start, end },
            );
        }

        setCard(
            (current) =>
                current && { ...current, placement: place(current.id) },
        );
    }, [place]);

    const frame = useRef(0);
    const onScroll = useCallback(() => {
        cancelAnimationFrame(frame.current);
        frame.current = requestAnimationFrame(syncScroll);
    }, [syncScroll]);

    useLayoutEffect(() => {
        const element = scroller.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(onScroll);
        observer.observe(element);
        if (element.firstElementChild) {
            observer.observe(element.firstElementChild);
        }
        window.addEventListener('resize', onScroll);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', onScroll);
            cancelAnimationFrame(frame.current);
        };
    }, [onScroll]);

    const onKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        const current = ids.findIndex((id) => slots.get(id) === event.target);
        const next = nextIndex(
            current === -1 ? rovingIndex : current,
            event.key,
            ids.length,
        );

        if (next === null) {
            return;
        }

        event.preventDefault();
        setFocusIndex(next);
        slots.get(ids[next])?.focus();
    };

    const sheetPerson =
        sheet && sheet.id !== you
            ? people.find((person) => person.id === sheet.id)
            : undefined;
    const isMySheet = sheet?.id === you && myStream !== null;
    const anchor = isOpen ? slots.get(card.id) : undefined;
    const lane = laneColor(openPerson ? openPerson.lane : me.lane);

    return (
        <section
            ref={section}
            aria-label="Live now from people you follow"
            className="border-b"
            data-test="orbit-rail"
        >
            <div
                ref={scroller}
                onScroll={onScroll}
                className="h-21 snap-x snap-mandatory scroll-px-4 scrollbar-none overflow-x-auto overflow-y-hidden"
                // The edges fade only on a side there is more rail to scroll to, so a row that fits has no fade at all.
                style={
                    edges.start && edges.end
                        ? undefined
                        : {
                              maskImage: `linear-gradient(to right, ${edges.start ? 'black' : 'transparent'}, black 24px, black calc(100% - 24px), ${edges.end ? 'black' : 'transparent'})`,
                          }
                }
            >
                <div className="relative w-max">
                    {people.length > 0 && (
                        <LaneLine
                            color={
                                activeId === null
                                    ? 'var(--muted-foreground)'
                                    : lane
                            }
                        />
                    )}
                    <ul
                        onKeyDown={onKeyDown}
                        className="flex h-21 w-max gap-1 px-4 pt-2.5"
                    >
                        <OrbitRailYou
                            user={{ ...me, live: myStream }}
                            isActive={activeId === you}
                            tabIndex={rovingIndex === 0 ? 0 : -1}
                            {...handlers.get(you)!}
                        />
                        {people.map((person, index) => (
                            <OrbitRailItem
                                key={person.id}
                                user={person}
                                isActive={activeId === person.id}
                                tabIndex={rovingIndex === index + 1 ? 0 : -1}
                                {...handlers.get(person.id)!}
                            />
                        ))}
                    </ul>
                </div>
            </div>

            <PopoverPrimitive.Root
                open={isOpen}
                onOpenChange={(open) => !open && close()}
            >
                {anchor && (
                    <PopoverPrimitive.Anchor virtualRef={{ current: anchor }} />
                )}
                <PopoverPrimitive.Portal>
                    <PopoverPrimitive.Content
                        side="bottom"
                        align="start"
                        sideOffset={10}
                        alignOffset={card?.placement.alignOffset}
                        // The card is clamped inside the feed column by `cardPlacement` and never flips above the rail.
                        avoidCollisions={false}
                        aria-label={
                            openPerson
                                ? `${openPerson.name} is live`
                                : 'Your stream'
                        }
                        onOpenAutoFocus={(event) => event.preventDefault()}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            restoreFocus.current?.focus();
                            restoreFocus.current = null;
                        }}
                        onEscapeKeyDown={() => {
                            restoreFocus.current =
                                (card && slots.get(card.id)) ?? null;
                        }}
                        onInteractOutside={(event) => {
                            // A ring takes the card over on its own click, so pressing one must not close it first.
                            if (
                                event.target instanceof Node &&
                                section.current?.contains(event.target)
                            ) {
                                event.preventDefault();
                            }
                        }}
                        onPointerEnter={hover.onPointerEnter}
                        onPointerLeave={hover.onPointerLeave}
                        className="relative z-40 pt-3.5 outline-none"
                        style={{ width: card?.placement.width }}
                        data-test="orbit-rail-card"
                    >
                        {isOpen && (
                            <OrbitRailCard
                                key={card.id}
                                lane={lane}
                                originX={card.placement.originX}
                                focusRequest={card.focusRequest}
                            >
                                {openPerson ? (
                                    <PersonCard
                                        person={openPerson}
                                        onNavigate={close}
                                        onPlayingChange={(playing) => {
                                            isPlaying.current = playing;
                                            if (playing) {
                                                setCard(
                                                    (current) =>
                                                        current && {
                                                            ...current,
                                                            pinned: true,
                                                        },
                                                );
                                            }
                                        }}
                                    />
                                ) : (
                                    myStream && (
                                        <MyCard me={me} stream={myStream} />
                                    )
                                )}
                            </OrbitRailCard>
                        )}
                    </PopoverPrimitive.Content>
                </PopoverPrimitive.Portal>
            </PopoverPrimitive.Root>

            <LiveStreamSheet
                title={
                    sheetPerson ? `${sheetPerson.name} is live` : 'Your stream'
                }
                open={
                    (sheet?.open ?? false) &&
                    (sheetPerson !== undefined || isMySheet)
                }
                onOpenChange={(open: boolean) =>
                    setSheet((current) => current && { ...current, open })
                }
            >
                {sheetPerson ? (
                    <PersonCard
                        person={sheetPerson}
                        onNavigate={() =>
                            setSheet(
                                (current) =>
                                    current && { ...current, open: false },
                            )
                        }
                    />
                ) : (
                    isMySheet && <MyCard me={me} stream={myStream} />
                )}
            </LiveStreamSheet>

            <GoLiveDialog open={isGoingLive} onOpenChange={setIsGoingLive} />
        </section>
    );
}

/**
 * The rail's lane from the first ring's centre to the last, with a pulse of the open card's lane travelling along it.
 */
function LaneLine({ color }: { color: string }) {
    const traveller = useRef<HTMLSpanElement>(null);

    // The traveller spans the whole line with the pulse at its end, so -100% to +pulse crosses any rail length.
    useMountAnimations(() => [
        traveller.current?.animate(
            { transform: ['translateX(-100%)', 'translateX(120px)'] },
            { duration: 5500, iterations: Infinity, easing: 'linear' },
        ),
    ]);

    return (
        <span
            aria-hidden
            className="pointer-events-none absolute inset-x-11 top-[31px] h-0.5 overflow-hidden rounded-[1px] bg-rail"
        >
            <span
                ref={traveller}
                className="absolute inset-0 hidden motion-safe:block"
            >
                <span
                    className="absolute inset-y-0 right-0 w-30"
                    style={{
                        backgroundImage: `linear-gradient(90deg, transparent, ${color}, transparent)`,
                    }}
                />
            </span>
        </span>
    );
}

function PersonCard({
    person,
    onNavigate,
    onPlayingChange,
}: {
    person: LiveFollowingUser;
    onNavigate: () => void;
    onPlayingChange?: (isPlaying: boolean) => void;
}) {
    return (
        <LivePreviewCard
            user={person}
            stream={person.live}
            latestCommit={person.latest_commit}
            onPlayingChange={onPlayingChange}
            actions={
                <Button variant="outline" asChild>
                    <Link
                        href={show(person)}
                        onClick={onNavigate}
                        data-test="orbit-rail-profile"
                    >
                        View profile
                    </Link>
                </Button>
            }
        />
    );
}

function MyCard({ me, stream }: { me: Profile; stream: LiveStream }) {
    const { endStream, isEnding } = useEndStream();

    return (
        <LivePreviewCard
            user={me}
            stream={stream}
            actions={
                <Button
                    variant="outline"
                    disabled={isEnding}
                    onClick={endStream}
                    data-test="orbit-rail-end-stream"
                >
                    <Square />
                    End stream
                </Button>
            }
        />
    );
}
