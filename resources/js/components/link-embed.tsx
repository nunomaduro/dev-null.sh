import { AudioLines, Code2, PictureInPicture2, Play } from 'lucide-react';
import type { CSSProperties, ReactNode, Ref } from 'react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { ActionTooltip } from '@/components/action-tooltip';
import { cn } from '@/lib/utils';
import {
    canPlayFloating,
    playInPlace,
    playVideo,
    playVideoHere,
    popOutVideo,
    registerVideoSlot,
    useDockedVideo,
    useHeldSlotOwner,
    useIsPlayingInPlace,
    useIsVideoFloating,
} from '@/lib/video-dock';
import { parseAspectRatio } from '@/lib/video-dock-geometry';
import type { Embed, LinkPreview } from '@/types';

const icons = { video: Play, audio: AudioLines, code: Code2 };

function isTall(embed: Embed): boolean {
    const aspectRatio = parseAspectRatio(embed.aspect_ratio);

    return aspectRatio !== null && aspectRatio < 1;
}

function frameStyle(embed: Embed): CSSProperties {
    return embed.aspect_ratio === null
        ? { height: embed.height ?? undefined }
        : { aspectRatio: embed.aspect_ratio };
}

function isAsciinemaRecording(embed: Embed): boolean {
    return (
        embed.provider === 'asciinema' &&
        /^https:\/\/asciinema\.org\/a\/\w+\/iframe(?:[?#].*)?$/.test(embed.src)
    );
}

function isAsciinemaBodySize(
    data: unknown,
): data is { type: 'bodySize'; payload: { height: number } } {
    return (
        typeof data === 'object' &&
        data !== null &&
        'type' in data &&
        data.type === 'bodySize' &&
        'payload' in data &&
        typeof data.payload === 'object' &&
        data.payload !== null &&
        'height' in data.payload &&
        typeof data.payload.height === 'number' &&
        Number.isFinite(data.payload.height) &&
        data.payload.height > 0
    );
}

export function EmbedFrame({ embed, title }: { embed: Embed; title: string }) {
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const recording = isAsciinemaRecording(embed);
    const [height, setHeight] = useState(embed.height ?? 420);

    useEffect(() => {
        if (!recording) {
            return;
        }

        const iframe = iframeRef.current;
        const resize = (event: MessageEvent<unknown>) => {
            if (
                event.origin !== 'https://asciinema.org' ||
                event.source !== iframe?.contentWindow ||
                !isAsciinemaBodySize(event.data)
            ) {
                return;
            }

            setHeight(event.data.payload.height);
        };

        window.addEventListener('message', resize);

        return () => window.removeEventListener('message', resize);
    }, [recording]);

    return (
        <iframe
            ref={iframeRef}
            src={embed.src}
            title={title}
            allow="autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write"
            sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation allow-forms"
            referrerPolicy="strict-origin-when-cross-origin"
            allowFullScreen
            style={recording ? { height } : frameStyle(embed)}
            className="block w-full border-0 bg-muted"
            data-test="link-embed-frame"
        />
    );
}

function Thumbnail({
    src,
    className,
}: {
    src: string | null;
    className?: string;
}) {
    const [isBroken, setIsBroken] = useState(false);

    if (!src || isBroken) {
        return <span className={cn('bg-muted', className)} />;
    }

    return (
        <img
            src={src}
            alt=""
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => setIsBroken(true)}
            className={cn('bg-muted object-cover', className)}
        />
    );
}

export function PlayerFrame({
    src,
    title,
    style,
    className,
    ref,
}: {
    src: string;
    title: string;
    style?: CSSProperties;
    className?: string;
    ref?: Ref<HTMLIFrameElement>;
}) {
    return (
        <iframe
            ref={ref}
            src={src}
            title={title}
            allow="autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write"
            sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation allow-forms"
            referrerPolicy="strict-origin-when-cross-origin"
            allowFullScreen
            style={style}
            className={cn('block w-full border-0 bg-muted', className)}
            data-test="link-embed-frame"
        />
    );
}

/**
 * Where a playing video sits in the post. The player itself lives in the layout and is laid over this slot, so it keeps
 * playing in a corner of the screen once the post is gone. While the video floats, or plays in another post, the slot
 * says so and offers to bring it here. Otherwise, while the player sits on it, flies in or is about to appear, the slot
 * only shows the poster, so nothing is read out, focused or glimpsed behind the video.
 */
function VideoSlot({
    embed,
    poster,
    owner,
    isHeld,
    isHeldElsewhere,
}: {
    embed: Embed;
    poster: string | null;
    owner: string;
    isHeld: boolean;
    isHeldElsewhere: boolean;
}) {
    const element = useRef<HTMLDivElement | null>(null);
    const isFloating = useIsVideoFloating();
    const isElsewhere = !isHeld && (isFloating || isHeldElsewhere);
    const register = useCallback(
        (node: HTMLDivElement | null) => {
            element.current = node;

            return node === null
                ? undefined
                : registerVideoSlot(embed.src, node, owner);
        },
        [embed.src, owner],
    );

    return (
        <div
            ref={register}
            style={frameStyle(embed)}
            className="relative block w-full overflow-hidden"
            data-test="link-embed-slot"
        >
            {!isElsewhere && !isHeld && (
                <Thumbnail
                    src={poster}
                    className="absolute inset-0 size-full"
                />
            )}
            {isElsewhere && (
                <>
                    <Thumbnail
                        src={poster}
                        className="absolute inset-0 size-full scale-110 opacity-50 blur-md"
                    />
                    <span className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/50 p-4 text-center text-white">
                        <PictureInPicture2
                            aria-hidden
                            className="size-7 opacity-80"
                        />
                        <span className="text-sm font-medium">
                            {isFloating
                                ? 'Playing in picture-in-picture'
                                : 'Playing in another post'}
                        </span>
                        <button
                            type="button"
                            onClick={() =>
                                element.current !== null &&
                                playVideoHere(element.current)
                            }
                            className="flex cursor-pointer items-center gap-1.5 rounded-full bg-white/15 px-3.5 py-1.5 text-sm font-medium ring-1 ring-white/25 backdrop-blur-sm transition-colors hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"
                            data-test="link-embed-play-here"
                        >
                            <Play
                                aria-hidden
                                className="size-3.5 fill-current"
                            />
                            Play here
                        </button>
                    </span>
                </>
            )}
        </div>
    );
}

function Details({ preview, embed }: { preview: LinkPreview; embed: Embed }) {
    return (
        <span className="flex min-w-0 flex-1 flex-col justify-center text-left">
            <span className="truncate font-mono text-[13px] text-muted-foreground">
                {preview.site_name ?? embed.provider}
            </span>
            {preview.title && (
                <span className="line-clamp-2 text-[15px] leading-snug font-medium wrap-break-word">
                    {preview.title}
                </span>
            )}
        </span>
    );
}

function PlayTrigger({
    isStatic,
    label,
    onPlay,
    style,
    className,
    children,
}: {
    isStatic: boolean;
    label: string;
    onPlay: () => void;
    style?: CSSProperties;
    className: string;
    children: ReactNode;
}) {
    if (isStatic) {
        return (
            <span style={style} className={className}>
                {children}
            </span>
        );
    }

    return (
        <button
            type="button"
            onClick={onPlay}
            aria-label={label}
            style={style}
            className={cn(
                'cursor-pointer focus-visible:outline-none',
                className,
            )}
            data-test="link-embed-play"
        >
            {children}
        </button>
    );
}

export function LinkEmbed({
    preview,
    embed,
    isStatic = false,
}: {
    preview: LinkPreview;
    embed: Embed;
    isStatic?: boolean;
}) {
    const owner = useId();
    const container = useRef<HTMLDivElement | null>(null);
    const [isPlaying, setIsPlaying] = useState(false);
    const [canFloat, setCanFloat] = useState(true);
    const isVideo = embed.aspect_ratio !== null;
    const measure = useCallback(
        (element: HTMLDivElement | null) => {
            container.current = element;

            // Only a video in the page can float; nothing else needs its surroundings read.
            if (element !== null && isVideo && !isStatic) {
                setCanFloat(canPlayFloating(element));
            }
        },
        [isVideo, isStatic],
    );
    const dockedVideo = useDockedVideo();
    const isPlayingInPlace = useIsPlayingInPlace(owner);
    const heldOwner = useHeldSlotOwner();
    const isHeldHere = heldOwner === owner;
    const Icon = icons[embed.kind];
    const label = `Play ${preview.title ?? preview.url} from ${embed.provider}`;
    const poster = preview.image_url ?? embed.poster ?? null;
    const title = preview.title ?? `${embed.provider} player`;
    const aspectRatio = parseAspectRatio(embed.aspect_ratio);
    const isDocked = !isStatic && canFloat && dockedVideo?.src === embed.src;
    const play = () => {
        if (!isVideo) {
            setIsPlaying(true);
        } else if (
            aspectRatio === null ||
            container.current === null ||
            !canPlayFloating(container.current)
        ) {
            playInPlace(owner);
        } else {
            playVideo(
                { src: embed.src, title, aspectRatio },
                container.current,
            );
        }
    };

    const frame = (
        <EmbedFrame
            key={embed.src}
            embed={embed}
            title={preview.title ?? `${embed.provider} player`}
        />
    );

    return (
        <div
            ref={measure}
            onClick={isStatic ? undefined : (event) => event.stopPropagation()}
            className="mt-3 overflow-hidden rounded-lg border"
            data-test="link-embed"
            data-provider={embed.provider}
        >
            {isVideo ? (
                <>
                    <div
                        className={cn(
                            'mx-auto bg-black',
                            isTall(embed) && 'max-w-[min(100%,20rem)]',
                        )}
                    >
                        {isPlayingInPlace ? (
                            frame
                        ) : isDocked ? (
                            <VideoSlot
                                embed={embed}
                                poster={poster}
                                owner={owner}
                                isHeld={isHeldHere}
                                isHeldElsewhere={
                                    heldOwner !== null && !isHeldHere
                                }
                            />
                        ) : (
                            <PlayTrigger
                                isStatic={isStatic}
                                label={label}
                                onPlay={play}
                                style={frameStyle(embed)}
                                className="group relative block w-full overflow-hidden"
                            >
                                <Thumbnail
                                    src={poster}
                                    className="absolute inset-0 size-full"
                                />
                                <span className="absolute inset-0 bg-black/20 transition-colors group-hover:bg-black/10" />
                                <span className="absolute top-1/2 left-1/2 flex size-14 -translate-1/2 items-center justify-center rounded-full bg-black/70 text-white ring-white/60 transition-transform group-hover:scale-105 group-focus-visible:ring-2">
                                    <Icon className="ml-0.5 size-6 fill-current" />
                                </span>
                            </PlayTrigger>
                        )}
                    </div>
                    {isStatic ? (
                        <span className="flex border-t px-3 py-2">
                            <Details preview={preview} embed={embed} />
                        </span>
                    ) : (
                        <div className="flex border-t">
                            <a
                                href={preview.url}
                                target="_blank"
                                rel="noopener noreferrer nofollow ugc"
                                className="flex min-w-0 flex-1 px-3 py-2 transition-colors hover:bg-accent focus-visible:bg-accent"
                            >
                                <Details preview={preview} embed={embed} />
                            </a>
                            {isDocked && isHeldHere && (
                                <span className="flex items-center pr-2 pl-1">
                                    <ActionTooltip label="Picture-in-picture">
                                        <button
                                            type="button"
                                            onClick={popOutVideo}
                                            aria-label="Play in picture-in-picture"
                                            className="flex size-9 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:bg-accent focus-visible:outline-none"
                                            data-test="link-embed-pop-out"
                                        >
                                            <PictureInPicture2 className="size-5" />
                                        </button>
                                    </ActionTooltip>
                                </span>
                            )}
                        </div>
                    )}
                </>
            ) : isPlaying ? (
                frame
            ) : (
                <PlayTrigger
                    isStatic={isStatic}
                    label={label}
                    onPlay={play}
                    className={cn(
                        'group flex w-full items-center',
                        !isStatic &&
                            'transition-colors hover:bg-accent focus-visible:bg-accent',
                    )}
                >
                    <span className="relative my-2 ml-2 size-20 shrink-0 overflow-hidden rounded-md">
                        <Thumbnail src={poster} className="size-full" />
                        <span className="absolute inset-0 flex items-center justify-center bg-black/30 text-white transition-colors group-hover:bg-black/40">
                            <Icon className="size-7 fill-current" />
                        </span>
                    </span>
                    <span className="flex min-w-0 flex-1 px-3 py-2">
                        <Details preview={preview} embed={embed} />
                    </span>
                </PlayTrigger>
            )}
        </div>
    );
}
