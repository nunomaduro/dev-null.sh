import { Link } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { LiveAvatar } from '@/components/live/live-avatar';
import { LiveUntil } from '@/components/live/live-preview-card';
import { PlatformGlyph } from '@/components/live/platform-glyph';
import {
    StreamPlayer,
    usePlayingStream,
    WatchStreamButton,
} from '@/components/live/stream-player';
import { Button } from '@/components/ui/button';
import { DialogOverlay, DialogPortal } from '@/components/ui/dialog';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useHoverIntent } from '@/hooks/use-hover-intent';
import { platformName } from '@/lib/live';
import { show } from '@/routes/user';
import type { LiveStream, User } from '@/types';

/**
 * What is on and where, with the provider's link-out. With `canPlay` the thumbnail plays the stream in place, so
 * only turn it on where the card stays put while it plays (a sheet, or a popover that pins itself through
 * `onPlayingChange`); elsewhere the thumbnail links out like the button.
 */
export function LiveStreamCard({
    user,
    stream,
    canPlay = false,
    onPlayingChange,
}: {
    user: Pick<User, 'name'>;
    stream: LiveStream;
    canPlay?: boolean;
    onPlayingChange?: (isPlaying: boolean) => void;
}) {
    const [isPlaying, setIsPlaying] = usePlayingStream(stream, onPlayingChange);

    return (
        <div data-test="live-stream-card">
            <StreamPlayer
                user={user}
                stream={canPlay ? stream : { ...stream, embed: null }}
                isPlaying={isPlaying}
                onPlayingChange={setIsPlaying}
            />
            <p className="mt-3 line-clamp-2 font-semibold text-pretty">
                {user.name} is live
            </p>
            <p className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
                <PlatformGlyph platform={stream.platform} />
                {platformName(stream.platform)}
            </p>
            <LiveUntil endsAt={stream.ends_at} className="mt-1" />
            <WatchStreamButton stream={stream} className="mt-3 w-full" />
        </div>
    );
}

/**
 * Wraps whatever marks a user as live. With a mouse, hovering it shows the stream's card, unless `hoverCard` is off
 * because a user preview already shows it there; pressing play pins that card open until it is dismissed. On touch,
 * where nothing hovers, tapping it opens the card as a sheet instead of following the link it wraps.
 */
export function LiveStreamTrigger({
    user,
    hoverCard = true,
    showProfile = true,
    className,
    children,
}: {
    user: Pick<User, 'name' | 'username' | 'avatar_url' | 'lane' | 'live'>;
    hoverCard?: boolean;
    showProfile?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const [isHoverOpen, setIsHoverOpen] = useState(false);
    const [isPinned, setIsPinned] = useState(false);
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const hover = useHoverIntent({
        openDelay: 300,
        onChange: (isHovering) => {
            if (isHovering || !isPinned) {
                setIsHoverOpen(isHovering);
            }
        },
    });

    const stream = user.live;

    if (!stream) {
        return <span className={className}>{children}</span>;
    }

    return (
        <Popover
            open={hoverCard && isHoverOpen}
            onOpenChange={(open) => {
                setIsHoverOpen(open);

                if (!open) {
                    setIsPinned(false);
                }
            }}
        >
            <PopoverAnchor asChild>
                <span
                    className={className}
                    onPointerEnter={hover.onPointerEnter}
                    onPointerLeave={hover.onPointerLeave}
                    onClickCapture={(event) => {
                        if (window.matchMedia('(hover: hover)').matches) {
                            return;
                        }

                        event.preventDefault();
                        event.stopPropagation();
                        setIsSheetOpen(true);
                    }}
                >
                    {children}
                </span>
            </PopoverAnchor>

            <PopoverContent
                align="start"
                sideOffset={8}
                className="w-76 rounded-lg p-3"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onPointerEnter={hover.onPointerEnter}
                onPointerLeave={hover.onPointerLeave}
            >
                <LiveStreamCard
                    user={user}
                    stream={stream}
                    canPlay
                    onPlayingChange={setIsPinned}
                />
            </PopoverContent>

            <LiveStreamSheet
                title={`${user.name} is live`}
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
            >
                <LiveStreamCard user={user} stream={stream} canPlay />

                {showProfile && (
                    <div className="mt-4 flex items-center gap-3 border-t pt-4">
                        <LiveAvatar user={user} size={40} surface="popover" />
                        <div className="min-w-0 flex-1">
                            <p className="truncate font-semibold">
                                {user.name}
                            </p>
                            <p className="truncate font-mono text-[13px] text-muted-foreground">
                                @{user.username}
                            </p>
                        </div>
                        <Button variant="secondary" asChild>
                            <Link
                                href={show(user)}
                                onClick={() => setIsSheetOpen(false)}
                            >
                                Open profile
                            </Link>
                        </Button>
                    </div>
                )}
            </LiveStreamSheet>
        </Popover>
    );
}

/**
 * A sheet from the bottom on phones, and a small dialog on wider touch screens such as tablets, for whatever shows a
 * stream where nothing hovers. `title` names it for screen readers; the content shows who is live.
 */
export function LiveStreamSheet({
    title,
    open,
    onOpenChange,
    children,
}: {
    title: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: ReactNode;
}) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPortal>
                <DialogOverlay className="bg-black/50" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    className="fixed inset-x-0 bottom-0 z-50 max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain rounded-t-lg border-t bg-popover p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-popover-foreground shadow-lg duration-200 data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:animate-in data-[state=open]:slide-in-from-bottom sm:inset-x-auto sm:top-1/2 sm:bottom-auto sm:left-1/2 sm:w-96 sm:-translate-1/2 sm:rounded-lg sm:border sm:data-[state=closed]:fade-out-0 sm:data-[state=closed]:slide-out-to-bottom-0 sm:data-[state=open]:fade-in-0 sm:data-[state=open]:slide-in-from-bottom-0"
                    data-test="live-stream-sheet"
                >
                    <span
                        aria-hidden
                        className="mx-auto mb-3 block h-1 w-10 rounded-full bg-muted-foreground/30 sm:hidden"
                    />
                    <DialogPrimitive.Title className="sr-only">
                        {title}
                    </DialogPrimitive.Title>

                    {children}
                </DialogPrimitive.Content>
            </DialogPortal>
        </DialogPrimitive.Root>
    );
}
