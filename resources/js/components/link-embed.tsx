import { AudioLines, Code2, Play } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import type { Embed, LinkPreview } from '@/types';

const icons = { video: Play, audio: AudioLines, code: Code2 };

function isTall(embed: Embed): boolean {
    const [width, height] = (embed.aspect_ratio ?? '')
        .split('/')
        .map((side) => Number.parseFloat(side));

    return width > 0 && height > width;
}

function frameStyle(embed: Embed): CSSProperties {
    return embed.aspect_ratio === null
        ? { height: embed.height ?? undefined }
        : { aspectRatio: embed.aspect_ratio };
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

function Details({ preview, embed }: { preview: LinkPreview; embed: Embed }) {
    return (
        <span className="flex min-w-0 flex-1 flex-col justify-center text-left">
            <span className="truncate font-mono text-[13px] text-muted-foreground">
                {preview.site_name ?? embed.provider}
            </span>
            {preview.title && (
                <span className="line-clamp-2 text-[15px] leading-snug font-medium break-words">
                    {preview.title}
                </span>
            )}
        </span>
    );
}

export function LinkEmbed({
    preview,
    embed,
}: {
    preview: LinkPreview;
    embed: Embed;
}) {
    const [isPlaying, setIsPlaying] = useState(false);
    const Icon = icons[embed.kind];
    const label = `Play ${preview.title ?? preview.url} from ${embed.provider}`;
    const isVideo = embed.aspect_ratio !== null;
    const poster = preview.image_url ?? embed.poster ?? null;

    const frame = (
        <iframe
            src={embed.src}
            title={preview.title ?? `${embed.provider} player`}
            allow="autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write"
            sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation allow-forms"
            referrerPolicy="strict-origin-when-cross-origin"
            allowFullScreen
            style={frameStyle(embed)}
            className="block w-full border-0 bg-muted"
            data-test="link-embed-frame"
        />
    );

    return (
        <div
            onClick={(event) => event.stopPropagation()}
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
                        {isPlaying ? (
                            frame
                        ) : (
                            <button
                                type="button"
                                onClick={() => setIsPlaying(true)}
                                aria-label={label}
                                style={frameStyle(embed)}
                                className="group relative block w-full cursor-pointer overflow-hidden focus-visible:outline-none"
                                data-test="link-embed-play"
                            >
                                <Thumbnail
                                    src={poster}
                                    className="absolute inset-0 size-full"
                                />
                                <span className="absolute inset-0 bg-black/20 transition-colors group-hover:bg-black/10" />
                                <span className="absolute top-1/2 left-1/2 flex size-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-black/70 text-white ring-white/60 transition-transform group-hover:scale-105 group-focus-visible:ring-2">
                                    <Icon className="ml-0.5 size-6 fill-current" />
                                </span>
                            </button>
                        )}
                    </div>
                    <a
                        href={preview.url}
                        target="_blank"
                        rel="noopener noreferrer nofollow ugc"
                        className="flex border-t px-3 py-2 transition-colors hover:bg-accent focus-visible:bg-accent"
                    >
                        <Details preview={preview} embed={embed} />
                    </a>
                </>
            ) : isPlaying ? (
                frame
            ) : (
                <button
                    type="button"
                    onClick={() => setIsPlaying(true)}
                    aria-label={label}
                    className="group flex w-full cursor-pointer items-center transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    data-test="link-embed-play"
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
                </button>
            )}
        </div>
    );
}
