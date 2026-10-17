import { cn } from '@/lib/utils';
import type { CommitGif } from '@/types/commit';

/** An attached GIPHY GIF, hotlinked verbatim with its required attribution. */
export function CommitGiphy({
    gif,
    className,
}: {
    gif: CommitGif;
    className?: string;
}) {
    return (
        <figure
            className={cn(
                'relative mt-3 overflow-hidden rounded-lg border',
                className,
            )}
            data-test="commit-giphy"
        >
            <img
                src={gif.url}
                alt={gif.title ?? 'Attached GIF'}
                width={gif.width ?? undefined}
                height={gif.height ?? undefined}
                loading="lazy"
                referrerPolicy="no-referrer"
                onError={(event) => {
                    const image = event.currentTarget;
                    if (image.getAttribute('src') !== '/favicon.svg') {
                        image.src = '/favicon.svg';
                        image.classList.replace(
                            'object-cover',
                            'object-contain',
                        );
                    }
                }}
                className="max-h-[32rem] w-full bg-muted object-cover"
            />
            <a
                href={gif.page_url}
                target="_blank"
                rel="noopener noreferrer"
                onClick={(event) => event.stopPropagation()}
                className="absolute right-1.5 bottom-1.5 rounded bg-background/80 px-1.5 py-0.5 text-[11px] font-medium text-foreground backdrop-blur transition hover:bg-background"
            >
                {gif.username
                    ? `GIF by @${gif.username} on GIPHY`
                    : 'via GIPHY'}
            </a>
        </figure>
    );
}
