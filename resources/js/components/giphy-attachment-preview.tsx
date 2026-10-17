import { X } from 'lucide-react';
import type { GiphyAttachment } from '@/lib/giphy';

export function GiphyAttachmentPreview({
    gif,
    onRemove,
}: {
    gif: GiphyAttachment | null;
    onRemove: () => void;
}) {
    if (gif === null) {
        return null;
    }

    return (
        <div className="mt-2">
            <div
                className="relative inline-block"
                data-test="giphy-attachment-preview"
            >
                <img
                    src={gif.url}
                    alt={gif.title ?? 'Attached GIF'}
                    referrerPolicy="no-referrer"
                    className="h-20 max-w-48 rounded-md border bg-muted object-cover"
                />
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label="Remove GIF"
                    className="absolute top-1 right-1 rounded-full bg-background/80 p-0.5 text-foreground backdrop-blur transition hover:bg-background"
                >
                    <X className="size-3.5" />
                </button>
                <span className="absolute bottom-1 left-1 rounded bg-background/80 px-1 text-[11px] leading-4 font-bold tracking-wide text-foreground backdrop-blur">
                    GIF
                </span>
            </div>
        </div>
    );
}
