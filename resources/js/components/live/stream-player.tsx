import { ArrowUpRight, ImageOff, Play } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { EmbedFrame } from '@/components/link-embed';
import { LiveBadge } from '@/components/live-badge';
import { Button } from '@/components/ui/button';
import { useNow } from '@/hooks/use-now';
import { platformName, thumbnailUrl, watchLabel } from '@/lib/live';
import { cn } from '@/lib/utils';
import type { LiveStream, User } from '@/types';

const subscribe = () => () => {};

/**
 * One attempt at one URL: a new cache-bust bucket remounts it, so a frame that failed gets another try.
 */
function ThumbnailImage({ src }: { src: string }) {
    const [isBroken, setIsBroken] = useState(false);

    if (isBroken) {
        return (
            <span
                aria-hidden
                className="absolute inset-0 flex items-center justify-center text-muted-foreground"
                data-test="stream-thumbnail-fallback"
            >
                <ImageOff className="size-6" />
            </span>
        );
    }

    return (
        // Not lazy: it only mounts once a card or sheet opens, and a bloom that starts clipped to a point would hold
        // a lazy image back until the entrance is over.
        <img
            src={src}
            alt=""
            decoding="async"
            referrerPolicy="no-referrer"
            onError={() => setIsBroken(true)}
            className="absolute inset-0 size-full object-cover"
        />
    );
}

/**
 * The stream's 16:9 thumbnail with the 5-minute cache-bust from `thumbnailUrl`, so it never shifts layout. The
 * bucket depends on the reader's clock, so the image only loads in the browser, never from the server's markup.
 */
function StreamThumbnail({
    stream,
    className,
}: {
    stream: Pick<LiveStream, 'thumbnail_url'>;
    className?: string;
}) {
    const isClient = useSyncExternalStore(
        subscribe,
        () => true,
        () => false,
    );
    const now = useNow();
    const src = thumbnailUrl(stream, now);

    return (
        <span
            className={cn(
                'relative block aspect-video overflow-hidden bg-muted',
                className,
            )}
            data-test="stream-thumbnail"
        >
            {isClient && <ThumbnailImage key={src} src={src} />}
        </span>
    );
}

/**
 * The play state for a `StreamPlayer`, one stream at a time: it is remembered by URL, so a card that starts showing
 * someone else's stream starts it paused. `onPlayingChange` hears every change, so a popover can pin itself open.
 */
export function usePlayingStream(
    stream: Pick<LiveStream, 'url'>,
    onPlayingChange?: (isPlaying: boolean) => void,
): [boolean, (isPlaying: boolean) => void] {
    const [playingUrl, setPlayingUrl] = useState<string | null>(null);

    return [
        playingUrl === stream.url,
        (isPlaying) => {
            setPlayingUrl(isPlaying ? stream.url : null);
            onPlayingChange?.(isPlaying);
        },
    ];
}

/**
 * The provider's link-out in its own words, "Watch on YouTube" or "Join on Twitch", opening in a new tab.
 */
export function WatchStreamButton({
    stream,
    className,
}: {
    stream: Pick<LiveStream, 'url' | 'platform'>;
    className?: string;
}) {
    return (
        <Button asChild className={className}>
            <a
                href={stream.url}
                target="_blank"
                rel="noopener noreferrer"
                data-test="live-stream-watch"
            >
                {watchLabel(stream.platform)}
                <ArrowUpRight />
            </a>
        </Button>
    );
}

/**
 * The thumbnail with a play button that swaps in the provider's player through `EmbedFrame` while `isPlaying`. With
 * no `stream.embed` it only links out to `stream.url`. Nothing from the provider loads until play is pressed.
 */
export function StreamPlayer({
    user,
    stream,
    isPlaying,
    onPlayingChange,
}: {
    user: Pick<User, 'name'>;
    stream: LiveStream;
    isPlaying: boolean;
    onPlayingChange: (isPlaying: boolean) => void;
}) {
    const platform = platformName(stream.platform);
    const frame =
        'relative block aspect-video overflow-hidden rounded-md bg-black';

    if (isPlaying && stream.embed) {
        return (
            <div
                className={cn(frame, '[&_iframe]:size-full')}
                data-test="stream-player"
            >
                <EmbedFrame
                    key={stream.embed.src}
                    embed={stream.embed}
                    title={`${user.name} live on ${platform}`}
                />
            </div>
        );
    }

    const poster = (
        <>
            <StreamThumbnail stream={stream} className="absolute inset-0" />
            <span className="absolute inset-0 bg-black/20 transition-colors group-hover:bg-black/10" />
            <LiveBadge className="absolute bottom-2 left-2" />
        </>
    );

    if (!stream.embed) {
        return (
            <a
                href={stream.url}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={`Watch ${user.name} live on ${platform}`}
                className={cn(frame, 'group')}
                data-test="stream-player"
            >
                {poster}
                <span className="absolute top-2 right-2 flex size-7 items-center justify-center rounded-full bg-black/70 text-white">
                    <ArrowUpRight className="size-4" />
                </span>
            </a>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onPlayingChange(true)}
            aria-label={`Play ${user.name}'s stream from ${platform}`}
            className={cn(frame, 'group w-full focus-visible:outline-none')}
            data-test="stream-play"
        >
            {poster}
            <span className="absolute top-1/2 left-1/2 flex size-13 -translate-1/2 items-center justify-center rounded-full bg-black/70 text-white ring-white/60 transition-transform group-hover:scale-105 group-focus-visible:ring-2 motion-reduce:transition-none">
                <Play className="ml-0.5 size-5 fill-current" />
            </span>
        </button>
    );
}
