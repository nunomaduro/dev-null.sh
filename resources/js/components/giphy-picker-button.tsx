import { usePage } from '@inertiajs/react';
import { ImagePlay } from 'lucide-react';
import { useState } from 'react';
import { GiphyPickerDialog } from '@/components/giphy-picker-dialog';
import { Button } from '@/components/ui/button';
import type { GiphyAttachment } from '@/lib/giphy';
import { cn } from '@/lib/utils';

/**
 * Invisible until the signed-in user connects their own GIPHY key in settings.
 */
export function GiphyPickerButton({
    disabled,
    hasGif,
    onPick,
    className,
}: {
    disabled?: boolean;
    hasGif: boolean;
    onPick: (gif: GiphyAttachment) => void;
    className?: string;
}) {
    const { giphy } = usePage().props;
    const [open, setOpen] = useState(false);

    if (giphy === null) {
        return null;
    }

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                disabled={disabled || hasGif}
                onClick={() => setOpen(true)}
                aria-label="Attach a GIF"
                title={hasGif ? 'One GIF per commit' : 'Attach a GIF'}
                className={cn('text-muted-foreground', className)}
                data-test="attach-gif-button"
            >
                <ImagePlay className="size-5" />
            </Button>
            <GiphyPickerDialog
                apiKey={giphy.key}
                open={open}
                onOpenChange={setOpen}
                onPick={onPick}
            />
        </>
    );
}
