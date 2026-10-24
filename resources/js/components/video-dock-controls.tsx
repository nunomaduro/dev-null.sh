import { Maximize2, X } from 'lucide-react';
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { corners } from '@/lib/video-dock-geometry';
import type { Corner } from '@/lib/video-dock-geometry';

/**
 * Each handle sits over a corner from just outside the player, its arc drawn for the top left and turned to fit.
 */
const resizeHandles: Record<Corner, string> = {
    'top-left': '-top-3.5 -left-3.5 cursor-nwse-resize',
    'top-right': '-top-3.5 -right-3.5 rotate-90 cursor-nesw-resize',
    'bottom-right': '-right-3.5 -bottom-3.5 rotate-180 cursor-nwse-resize',
    'bottom-left': '-bottom-3.5 -left-3.5 -rotate-90 cursor-nesw-resize',
};

type PointerHandler = (event: ReactPointerEvent<HTMLElement>) => void;

/**
 * The floating player's own controls, all outside the video so its area only ever shows the provider's player: a
 * resize handle around each corner, and below it a close button, the grabber and a full screen button.
 */
export function VideoDockControls({
    isActive,
    isInteracting,
    isFullscreenSupported,
    onClose,
    onFullscreen,
    onStartDrag,
    onStartResize,
    onMove,
    onEnd,
}: {
    isActive: boolean;
    isInteracting: boolean;
    isFullscreenSupported: boolean;
    onClose: () => void;
    onFullscreen: () => void;
    onStartDrag: PointerHandler;
    onStartResize: (
        event: ReactPointerEvent<HTMLElement>,
        handle: Corner,
    ) => void;
    onMove: PointerHandler;
    onEnd: PointerHandler;
}) {
    const pointerEvents = cn(
        'group-has-focus-visible/dock:pointer-events-auto [@media(hover:none)]:pointer-events-auto',
        { 'pointer-events-auto': isActive },
    );
    const tracking = {
        onPointerMove: onMove,
        onPointerUp: onEnd,
        onPointerCancel: onEnd,
        onLostPointerCapture: onEnd,
    };

    return (
        <div
            className={cn(
                'pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-200 group-has-focus-visible/dock:opacity-100 [@media(hover:none)]:opacity-100',
                { 'opacity-100': isActive },
            )}
        >
            {corners.map((handle) => (
                <div
                    key={handle}
                    onPointerDown={(event) => onStartResize(event, handle)}
                    {...tracking}
                    aria-hidden
                    className={cn(
                        'group/handle absolute size-10 touch-none',
                        resizeHandles[handle],
                        pointerEvents,
                    )}
                    data-test="video-dock-resize"
                >
                    <svg
                        viewBox="0 0 40 40"
                        className="size-full text-neutral-900 drop-shadow-[0_1px_3px_rgb(0_0_0/0.2)] transition-transform duration-200 group-hover/handle:scale-110 dark:text-white dark:drop-shadow-[0_1px_3px_rgb(0_0_0/0.45)]"
                    >
                        <path
                            d="M7 26A19 19 0 0 1 26 7"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={5}
                            strokeLinecap="round"
                        />
                    </svg>
                </div>
            ))}

            {/* As wide as the player, with the grabber giving way, so a narrow portrait player keeps its buttons on screen. */}
            <div className="absolute inset-x-0 top-[calc(100%+8px)] flex items-center justify-center gap-2">
                <DockButton
                    label="Close video"
                    onClick={onClose}
                    className={pointerEvents}
                >
                    <X className="size-4" />
                </DockButton>
                <div
                    onPointerDown={onStartDrag}
                    {...tracking}
                    aria-hidden
                    className={cn(
                        'group/grabber flex h-8 w-[clamp(3rem,calc(100%-5rem),8rem)] shrink-0 touch-none items-center justify-center select-none',
                        isInteracting ? 'cursor-grabbing' : 'cursor-grab',
                        pointerEvents,
                    )}
                    data-test="video-dock-handle"
                >
                    <span className="h-2 w-[85%] rounded-full bg-neutral-900 shadow-[0_2px_8px_rgb(0_0_0/0.2)] transition-[width] duration-200 group-hover/grabber:w-full dark:bg-white dark:shadow-[0_0_0_1px_rgb(0_0_0/0.15),0_2px_8px_rgb(0_0_0/0.35)]" />
                </div>
                <DockButton
                    label="Full screen"
                    onClick={onFullscreen}
                    className={cn(
                        pointerEvents,
                        !isFullscreenSupported && 'invisible',
                    )}
                >
                    <Maximize2 className="size-4" />
                </DockButton>
            </div>
        </div>
    );
}

function DockButton({
    label,
    onClick,
    className,
    children,
}: {
    label: string;
    onClick: () => void;
    className?: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            onPointerDown={(event) => event.stopPropagation()}
            className={cn(
                'flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full bg-neutral-900 text-white shadow-[0_2px_8px_rgb(0_0_0/0.2)] transition-colors hover:bg-neutral-700 focus-visible:ring-2 focus-visible:ring-neutral-900/40 focus-visible:ring-offset-2 focus-visible:outline-none dark:bg-white dark:text-neutral-900 dark:shadow-[0_2px_8px_rgb(0_0_0/0.35)] dark:hover:bg-neutral-200 dark:focus-visible:ring-white/60',
                className,
            )}
        >
            {children}
        </button>
    );
}
