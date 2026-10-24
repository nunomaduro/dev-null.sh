import type { PointerEvent as ReactPointerEvent } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createFrameLoop } from '@/lib/frame-loop';
import {
    closeVideo,
    currentPagePath,
    dockedVideo,
    readDockPlacement,
    setHeldSlot,
    setVideoFloating,
    subscribeToVideoDock,
    takeClaimedSlot,
    useIsVideoFloating,
    takePlayedFrom,
    takePopOutRequest,
    videoSlot,
    writeDockPlacement,
} from '@/lib/video-dock';
import type { DockedVideo } from '@/lib/video-dock';
import {
    ceilingAbove,
    documentBox,
    floorBelow,
    isSameBox,
    obstacles,
    ceilings,
    prefersReducedMotion,
    shownFraction,
    slotRadius,
    startFrame,
    toBox,
    viewport,
    watchCornerChanges,
} from '@/lib/video-dock-dom';
import {
    afterEmbedLeft,
    carriedBy,
    closeAction,
    ceilingGap,
    cornerBox,
    departureBox,
    dockMargin,
    dockSize,
    isCompact,
    isSpringSettled,
    lastSliver,
    nearestCorner,
    oppositeCorner,
    popOutBelow,
    resizedLength,
    restingVelocity,
    returnAbove,
    returnsByItself,
    stepSpring,
} from '@/lib/video-dock-geometry';
import type {
    Box,
    Corner,
    DockPlacement,
    Point,
} from '@/lib/video-dock-geometry';
import { playerApiSrc, watchPlayback } from '@/lib/video-player-state';
import type { Playback } from '@/lib/video-player-state';

/**
 * How long a page has to hold still before the player moves: long enough for the page to restore its scroll after a
 * visit, and for a list that re-renders the post to put it back.
 */
const settleDelay = 150;

/**
 * Frames further apart than this, like after the tab was in the background, move the spring no further than one would.
 */
const longestFrame = 1 / 20;
const frameLength = 1 / 60;

/**
 * Resting, the player checks its corner now and then even with nothing reported, in case something moved unseen.
 */
const restingCheck = 500;

type Stage =
    | 'pending'
    | 'inline'
    | 'holding'
    | 'to-slot'
    | 'to-corner'
    | 'floating';

type Interaction =
    | { kind: 'drag'; offset: Point; samples: (Point & { time: number })[] }
    | { kind: 'resize'; anchor: Corner; start: Box; length: number };

type Controller = {
    stage: Stage;
    since: number;
    slot: HTMLElement | null;
    home: HTMLElement | null;
    playedFrom: Element | null;
    candidate: HTMLElement | null;
    candidateSince: number;
    box: Box;
    lastSlotRect: Box;
    lastTarget: Box | null;
    velocity: Box;
    lastFrame: number;
    placement: DockPlacement;
    isPlacementCompact: boolean;
    interaction: Interaction | null;
    wasPoppedOutByHand: boolean;
    hiddenSince: number | null;
    wasInView: boolean;
    isEmerging: boolean;
    isFloating: boolean;
    playback: Playback;
    isAutomatic: boolean;
};

/**
 * The layer of its post, under the sticky headers, and the layer it floats on, over them.
 */
const inlineLayer = '1';
const floatingLayer = '40';

export type DockedPlayer = ReturnType<typeof useDockedPlayer>;

/**
 * Moves the one playing video between its embed and a corner of the screen. Everything it does happens in animation
 * frames, which only run while it is moving or waiting to see whether the page has settled; resting, it sleeps until
 * a scroll, a resize, a page change or a change to the video wakes it.
 */
export function useDockedPlayer(video: DockedVideo, isAutomatic: boolean) {
    const wrapper = useRef<HTMLDivElement>(null);
    const surface = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLIFrameElement>(null);
    const controller = useRef<Controller | null>(null);
    const wakeUp = useRef<() => void>(() => undefined);
    const [src] = useState(() =>
        playerApiSrc(video.src, window.location.origin),
    );
    const isFloating = useIsVideoFloating();
    const [isInteracting, setIsInteracting] = useState(false);

    /**
     * Kept in the store alone, so the player and its embeds hear it at once, in the same frame the player starts
     * moving, and none of them shows the wrong card.
     */
    const setIsFloating = (value: boolean) => {
        state().isFloating = value;
        applyLayer();
        setVideoFloating(value);
    };

    /**
     * Floating, it is over the sticky headers. Setting off from its post it stays under them until it has come out
     * from beneath, so what has already scrolled under a header doesn't flash over it as the player lifts off.
     */
    const applyLayer = () => {
        const current = state();

        if (wrapper.current !== null) {
            wrapper.current.style.zIndex =
                current.isFloating && !current.isEmerging
                    ? floatingLayer
                    : inlineLayer;
        }
    };

    const state = (): Controller => {
        if (controller.current === null) {
            // Created between frames, it measures the page afresh rather than trusting the last player's last frame.
            startFrame();

            controller.current = {
                stage: 'pending',
                since: performance.now(),
                slot: null,
                home: null,
                playedFrom: takePlayedFrom(),
                candidate: null,
                candidateSince: 0,
                box: { x: 0, y: 0, width: 0, height: 0 },
                lastSlotRect: { x: 0, y: 0, width: 0, height: 0 },
                lastTarget: null,
                velocity: restingVelocity,
                lastFrame: performance.now(),
                placement: readDockPlacement(viewport()),
                isPlacementCompact: isCompact(viewport()),
                interaction: null,
                wasPoppedOutByHand: false,
                hiddenSince: null,
                wasInView: false,
                isEmerging: false,
                isFloating: false,
                playback: 'unknown',
                isAutomatic,
            };
        }

        return controller.current;
    };

    const place = (position: 'absolute' | 'fixed', box: Box) => {
        const element = wrapper.current;

        if (element === null) {
            return;
        }

        element.style.position = position;
        element.style.transform = `translate3d(${box.x}px, ${box.y}px, 0)`;
        element.style.width = `${box.width}px`;
        element.style.height = `${box.height}px`;
        element.style.visibility = 'visible';
        state().box = box;
    };

    const setRadius = (radius: string) => {
        if (surface.current !== null) {
            surface.current.style.borderRadius = radius;
        }
    };

    /**
     * Pins the player to the viewport where the embed is now, so it can fly off from there.
     */
    const lift = () => {
        const current = state();

        if (current.stage === 'inline') {
            place('fixed', current.lastSlotRect);
        }
    };

    const cornerTarget = (): Box => {
        const current = state();

        // Turning a tablet or resizing a window across the breakpoint switches to the placement remembered for it.
        if (current.isPlacementCompact !== isCompact(viewport())) {
            current.isPlacementCompact = isCompact(viewport());
            current.placement = readDockPlacement(viewport());
        }

        const { placement } = current;
        const size = viewport();
        const whole = { x: 0, y: 0, width: size.width, height: size.height };

        // Between a header stuck along the top and a bar along the bottom, it stays small enough to keep its controls
        // on screen.
        const room = {
            width: size.width,
            height:
                Math.min(size.height, floorBelow(whole)) -
                Math.max(0, ceilingAbove(whole) + ceilingGap - dockMargin),
        };

        return cornerBox(
            placement.corner,
            dockSize(placement.length, video.aspectRatio, room),
            size,
            obstacles(),
            ceilings(),
        );
    };

    const attach = (slot: HTMLElement) => {
        const current = state();

        current.stage = 'inline';
        current.slot = slot;
        current.home = slot;
        current.candidate = null;
        current.hiddenSince = null;
        current.velocity = restingVelocity;
        current.lastSlotRect = toBox(slot.getBoundingClientRect());
        setRadius(slotRadius(slot));
        place('absolute', documentBox(slot));
        setHeldSlot(slot);
        setIsFloating(false);
    };

    const flyInto = (slot: HTMLElement) => {
        const current = state();

        lift();
        current.isEmerging = false;
        current.wasInView = false;
        current.stage = 'to-slot';
        current.lastTarget = null;
        current.slot = slot;
        current.candidate = null;
        current.hiddenSince = null;
        setRadius(slotRadius(slot));
        setHeldSlot(null);
        setIsFloating(false);
    };

    /**
     * Leaving its embed, the player sets off from the edge of the screen the embed left by, at rest, so it reads as the
     * same video sliding back into view rather than one arriving from out of sight.
     */
    const flyToCorner = () => {
        const current = state();

        if (current.stage === 'inline') {
            place(
                'fixed',
                departureBox(
                    current.lastSlotRect,
                    viewport(),
                    ceilingAbove(current.lastSlotRect),
                ),
            );
            current.velocity = restingVelocity;
            // What has already scrolled under a header must not flash over it as the player lifts off.
            current.isEmerging = true;
        }

        current.stage = 'to-corner';
        current.slot = null;
        current.candidate = null;
        current.hiddenSince = null;
        setRadius('');
        setHeldSlot(null);
        setIsFloating(true);
    };

    /**
     * Moves the player one frame along its spring toward where the target is now, and says whether it has arrived.
     */
    const chase = (target: Box, seconds: number, isHoming = false): boolean => {
        const current = state();

        if (prefersReducedMotion()) {
            current.velocity = restingVelocity;
            place('fixed', target);

            return true;
        }

        const spring = stepSpring(
            { position: current.box, velocity: current.velocity },
            target,
            seconds,
            isHoming,
        );
        const hasArrived = isSpringSettled(spring, target);

        current.velocity = hasArrived ? restingVelocity : spring.velocity;
        place('fixed', hasArrived ? target : spring.position);

        return hasArrived;
    };

    /**
     * A playing video leaves its embed once a quarter of it has scrolled away after it showed well, or all but a
     * sliver has otherwise, and it has stayed away a moment. Played while half off the screen, it doesn't fly off at
     * once.
     */
    const hasScrolledPast = (rect: DOMRect, now: number): boolean => {
        const current = state();
        const fraction = shownFraction(rect);

        if (fraction >= popOutBelow) {
            current.wasInView = true;
        }

        const isAway =
            current.isAutomatic &&
            current.playback !== 'paused' &&
            (fraction < lastSliver ||
                (current.wasInView && fraction < popOutBelow));

        if (!isAway) {
            current.hiddenSince = null;

            return false;
        }

        current.hiddenSince ??= now;

        return now - current.hiddenSince >= settleDelay;
    };

    /**
     * Only the page the video was playing on takes it back by itself; anywhere else, its embeds offer to.
     */
    const isOnHomePage = (): boolean =>
        currentPagePath() === dockedVideo()?.pagePath;

    /**
     * The embed the video was last in, or, if the page has re-rendered it, the first embed of the video on the page.
     */
    const homeSlot = (): HTMLElement | null => {
        if (!isOnHomePage()) {
            return null;
        }

        const { home } = state();

        return home !== null && home.isConnected ? home : videoSlot(video.src);
    };

    /**
     * Its embed gone, the player goes back to it if the page only re-rendered it, and otherwise leaves at once, floating
     * on or stopping as the setting says, instead of lingering over the next page.
     */
    const leaveEmbed = (now: number): boolean => {
        const current = state();
        const replacement = homeSlot();

        current.slot = null;

        if (replacement !== null) {
            flyInto(replacement);

            return true;
        }

        if (isOnHomePage()) {
            current.stage = 'holding';
            current.since = now;

            return true;
        }

        if (
            afterEmbedLeft(
                current.isAutomatic,
                current.playback === 'paused',
                false,
            ) === 'float'
        ) {
            flyToCorner();

            return true;
        }

        closeVideo();

        return false;
    };

    const close = () => {
        startFrame();

        const current = state();
        const slot = homeSlot();
        const fraction =
            slot === null ? null : shownFraction(slot.getBoundingClientRect());

        if (slot === null || closeAction(fraction) === 'stop') {
            closeVideo();

            return;
        }

        current.wasPoppedOutByHand = false;
        flyInto(slot);
        wakeUp.current();
    };

    /**
     * One frame of the player's life. It says whether it needs another frame straight away, or can sleep.
     */
    const step = (now: number, seconds: number): boolean => {
        // A player whose video has been closed or replaced has nothing left to do, and must not take requests meant for
        // the next one.
        if (dockedVideo()?.src !== video.src) {
            return false;
        }

        const current = state();
        const wantsPopOut = takePopOutRequest();
        const claimed = takeClaimedSlot();

        if (claimed !== null) {
            current.wasPoppedOutByHand = false;

            if (current.stage === 'pending') {
                attach(claimed);
            } else if (claimed !== current.slot || current.stage !== 'inline') {
                flyInto(claimed);
            }

            return true;
        }

        if (current.stage === 'pending') {
            const slot =
                current.playedFrom === null
                    ? homeSlot()
                    : videoSlot(video.src, current.playedFrom);

            // Just played from its embed, it starts there, however much of the embed is showing.
            if (slot !== null) {
                current.playedFrom = null;
                attach(slot);

                return false;
            }

            if (now - current.since >= settleDelay) {
                setIsFloating(true);
                place('fixed', cornerTarget());
                current.stage = 'floating';

                return false;
            }

            return true;
        }

        if (current.stage === 'inline') {
            const slot = current.slot;

            if (slot === null || !slot.isConnected) {
                lift();

                return leaveEmbed(now);
            }

            // Popped out by hand, it stays out on every page until Play here or closing it asks it back.
            if (wantsPopOut) {
                current.wasPoppedOutByHand = true;
                flyToCorner();

                return true;
            }

            const rect = slot.getBoundingClientRect();
            const box = documentBox(slot);

            current.lastSlotRect = toBox(rect);

            if (!isSameBox(box, current.box)) {
                place('absolute', box);
            }

            if (hasScrolledPast(rect, now)) {
                flyToCorner();

                return true;
            }

            return current.hiddenSince !== null;
        }

        if (current.stage === 'to-slot') {
            const slot = current.slot;

            if (slot === null || !slot.isConnected) {
                return leaveEmbed(now);
            }

            const rect = slot.getBoundingClientRect();

            // Scrolled away again before it got there, it turns back for the corner.
            if (hasScrolledPast(rect, now)) {
                flyToCorner();

                return true;
            }

            const target = toBox(rect);

            // Heading home, it moves with the embed as the page scrolls, so it only ever closes the gap between them.
            if (current.lastTarget !== null) {
                place(
                    'fixed',
                    carriedBy(current.box, current.lastTarget, target),
                );
            }

            current.lastTarget = target;

            if (chase(target, seconds, true)) {
                attach(slot);

                return false;
            }

            return true;
        }

        const slot = homeSlot();
        const visibleSlot =
            returnsByItself(current.isAutomatic, current.wasPoppedOutByHand) &&
            slot !== null &&
            shownFraction(slot.getBoundingClientRect()) >= returnAbove
                ? slot
                : null;

        if (visibleSlot !== current.candidate) {
            current.candidate = visibleSlot;
            current.candidateSince = now;
        }

        if (
            current.candidate !== null &&
            now - current.candidateSince >= settleDelay
        ) {
            flyInto(current.candidate);

            return true;
        }

        if (current.stage === 'holding') {
            if (isOnHomePage() && now - current.since < settleDelay) {
                return true;
            }

            const action = afterEmbedLeft(
                current.isAutomatic,
                current.playback === 'paused',
                slot !== null,
            );

            if (action === 'float') {
                flyToCorner();
            } else if (action === 'return' && slot !== null) {
                flyInto(slot);
            } else {
                closeVideo();

                return false;
            }

            return true;
        }

        if (current.stage === 'floating') {
            const target = cornerTarget();

            if (!isSameBox(target, current.box)) {
                current.stage = 'to-corner';

                return true;
            }

            return current.candidate !== null;
        }

        const hasArrived = chase(cornerTarget(), seconds);

        if (current.isEmerging && (hasArrived || isClear(current.box))) {
            current.isEmerging = false;
            applyLayer();
        }

        if (hasArrived) {
            current.stage = 'floating';
        }

        return true;
    };

    /**
     * Out from under every header above it and bar below it.
     */
    const isClear = (box: Box): boolean =>
        box.y >= ceilingAbove(box) - 0.5 &&
        box.y + box.height <= floorBelow(box) + 0.5;

    useLayoutEffect(() => {
        const loop = createFrameLoop((now, isWaking) => {
            const current = state();
            const seconds = isWaking
                ? frameLength
                : Math.min((now - current.lastFrame) / 1000, longestFrame);

            current.lastFrame = now;

            if (current.interaction !== null) {
                return false;
            }

            startFrame();

            return step(now, seconds);
        });

        wakeUp.current = loop.wake;

        // Set up again over a player already sitting in its embed, it says so again: the store forgets the embed only
        // when the video itself is closed or replaced, which is the only time the player really goes away.
        const current = state();

        if (current.stage === 'inline' && current.slot !== null) {
            setHeldSlot(current.slot);
        }

        const unsubscribe = subscribeToVideoDock(loop.wake);
        const unwatch = watchCornerChanges(loop.wake);

        // Resting in a corner, it still looks again now and then, for anything that moved without saying so.
        const checkCorner = window.setInterval(() => {
            if (state().stage === 'floating') {
                loop.wake();
            }
        }, restingCheck);

        loop.wake();

        return () => {
            loop.stop();
            window.clearInterval(checkCorner);
            unsubscribe();
            unwatch();
        };
        // The player is keyed by its video, and everything else it reads lives in the controller.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        state().isAutomatic = isAutomatic;
        wakeUp.current();
        // The controller is created once and lives as long as the player.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAutomatic]);

    useEffect(() => {
        if (frame.current === null) {
            return;
        }

        return watchPlayback(frame.current, src, (playback) => {
            state().playback = playback;
            wakeUp.current();
        });
        // The controller is created once and lives as long as the player.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [src]);

    const startInteraction = (
        event: ReactPointerEvent<HTMLElement>,
        interaction: Interaction,
    ) => {
        const current = state();

        // It can be caught mid-flight to its corner, as well as once it rests there.
        if (
            event.button !== 0 ||
            (current.stage !== 'floating' && current.stage !== 'to-corner')
        ) {
            return;
        }

        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        current.interaction = interaction;
        current.velocity = restingVelocity;
        current.isEmerging = false;
        applyLayer();
        setIsInteracting(true);
    };

    const startDrag = (event: ReactPointerEvent<HTMLElement>) => {
        const { box } = state();

        startInteraction(event, {
            kind: 'drag',
            offset: { x: event.clientX - box.x, y: event.clientY - box.y },
            samples: [
                { x: event.clientX, y: event.clientY, time: event.timeStamp },
            ],
        });
    };

    const startResize = (
        event: ReactPointerEvent<HTMLElement>,
        handle: Corner,
    ) => {
        const { box, placement } = state();

        startInteraction(event, {
            kind: 'resize',
            anchor: oppositeCorner(handle),
            start: box,
            length: placement.length,
        });
    };

    const move = (event: ReactPointerEvent<HTMLElement>) => {
        const current = state();
        const interaction = current.interaction;

        if (interaction === null) {
            return;
        }

        startFrame();

        const size = viewport();

        if (interaction.kind === 'drag') {
            const { box } = current;

            interaction.samples = [
                ...interaction.samples.filter(
                    (sample) => event.timeStamp - sample.time < 100,
                ),
                { x: event.clientX, y: event.clientY, time: event.timeStamp },
            ];

            place('fixed', {
                ...box,
                x: Math.min(
                    Math.max(event.clientX - interaction.offset.x, 0),
                    size.width - box.width,
                ),
                y: Math.min(
                    Math.max(event.clientY - interaction.offset.y, 0),
                    size.height - box.height,
                ),
            });

            return;
        }

        const { anchor, start } = interaction;
        const resized = dockSize(
            resizedLength(
                anchor,
                start,
                { x: event.clientX, y: event.clientY },
                video.aspectRatio,
            ),
            video.aspectRatio,
            size,
        );

        interaction.length = Math.max(resized.width, resized.height);
        place('fixed', {
            ...resized,
            x: anchor.endsWith('right')
                ? start.x + start.width - resized.width
                : start.x,
            y: anchor.startsWith('bottom')
                ? start.y + start.height - resized.height
                : start.y,
        });
    };

    const endInteraction = (event: ReactPointerEvent<HTMLElement>) => {
        const current = state();
        const interaction = current.interaction;

        if (interaction === null) {
            return;
        }

        startFrame();
        current.interaction = null;
        setIsInteracting(false);

        if (interaction.kind === 'drag') {
            const [first] = interaction.samples;
            const elapsed = Math.max(event.timeStamp - first.time, 1);
            const { box } = current;
            const fling = {
                x: (event.clientX - first.x) / elapsed,
                y: (event.clientY - first.y) / elapsed,
            };

            current.placement = {
                ...current.placement,
                corner: nearestCorner(
                    {
                        x: box.x + box.width / 2,
                        y: box.y + box.height / 2,
                    },
                    fling,
                    viewport(),
                ),
            };
            // The throw carries on into the spring, so letting go doesn't stop it dead before it settles.
            current.velocity = {
                x: fling.x * 1000,
                y: fling.y * 1000,
                width: 0,
                height: 0,
            };
        } else {
            current.placement = {
                ...current.placement,
                length: interaction.length,
            };
        }

        writeDockPlacement(current.placement, viewport());
        current.stage = 'to-corner';
        wakeUp.current();
    };

    return {
        wrapper,
        surface,
        frame,
        src,
        isFloating,
        isInteracting,
        close,
        startDrag,
        startResize,
        move,
        endInteraction,
    };
}
