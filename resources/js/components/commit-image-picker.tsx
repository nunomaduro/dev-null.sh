import { ImagePlus, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export const maxImages = 3;

export const maxDescriptionLength = 1000;

const maxImageBytes = 10 * 1024 * 1024;
const acceptedTypes = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Keeps the files the server would accept, and tells why any others were left out.
 */
export function pickImages(
    current: File[],
    picked: File[],
): { images: File[]; error: string | null } {
    const accepted = picked.filter(
        (file) =>
            acceptedTypes.includes(file.type) && file.size <= maxImageBytes,
    );
    const images = [...current, ...accepted].slice(0, maxImages);

    if (accepted.length < picked.length) {
        return {
            images,
            error: 'Only JPEG, PNG, or WebP images up to 10 MB.',
        };
    }

    if (current.length + accepted.length > maxImages) {
        return { images, error: `Up to ${maxImages} images per commit.` };
    }

    return { images, error: null };
}

/**
 * The first validation error the server gave for the images, whichever image it was about.
 */
export function imageErrorFrom(
    errors: Record<string, string | undefined>,
): string | undefined {
    const key = Object.keys(errors).find(
        (name) =>
            name === 'images' ||
            name.startsWith('images.') ||
            name.startsWith('image_descriptions') ||
            name.startsWith('giphy_'),
    );

    return key ? errors[key] : undefined;
}

const objectUrls = new WeakMap<File, string>();

/**
 * The object URL to show a picked image with, made once per image and kept until the image is let go.
 */
export function objectUrlFor(image: File): string {
    const url = objectUrls.get(image) ?? URL.createObjectURL(image);

    objectUrls.set(image, url);

    return url;
}

export function revokeObjectUrls(images: File[]): void {
    images.forEach((image) => {
        const url = objectUrls.get(image);

        if (url !== undefined) {
            URL.revokeObjectURL(url);
            objectUrls.delete(image);
        }
    });
}

export function ImagePickerButton({
    count,
    disabled,
    onPick,
    className,
}: {
    count: number;
    disabled?: boolean;
    onPick: (files: File[]) => void;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);

    return (
        <>
            <input
                ref={inputRef}
                type="file"
                accept={acceptedTypes.join(',')}
                multiple
                hidden
                onChange={(event) => {
                    onPick(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
                data-test="commit-image-input"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                disabled={disabled || count >= maxImages}
                onClick={() => inputRef.current?.click()}
                aria-label="Attach images"
                title={`Attach up to ${maxImages} images`}
                className={cn('text-muted-foreground', className)}
                data-test="attach-images-button"
            >
                <ImagePlus className="size-5" />
            </Button>
        </>
    );
}

export function ImageDropOverlay({ className }: { className?: string }) {
    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-primary bg-primary-soft/90 text-sm font-medium text-primary motion-safe:animate-in motion-safe:duration-150 motion-safe:fade-in-0',
                className,
            )}
            data-test="image-drop-overlay"
        >
            <ImagePlus className="size-5" />
            Drop images to attach
        </div>
    );
}

export function ImagePickerPreviews({
    images,
    descriptions,
    onDescribe,
    onRemove,
}: {
    images: File[];
    descriptions: string[];
    onDescribe: (index: number, description: string) => void;
    onRemove: (index: number) => void;
}) {
    const id = useId();
    const [describedIndex, setDescribedIndex] = useState<number | null>(null);
    const urls = images.map(objectUrlFor);

    if (images.length === 0) {
        return null;
    }

    const editing =
        describedIndex !== null && describedIndex < images.length
            ? describedIndex
            : null;
    const altButtonId = (index: number) => `${id}-alt-${index}`;

    const finishDescribing = () => {
        if (editing !== null) {
            document.getElementById(altButtonId(editing))?.focus();
        }

        setDescribedIndex(null);
    };

    return (
        <div className="mt-2">
            <ul className="flex gap-2" aria-label="Attached images">
                {urls.map((url, index) => {
                    const isDescribed = Boolean(descriptions[index]?.trim());

                    return (
                        <li key={url} className="relative">
                            <img
                                src={url}
                                alt={`Attached image ${index + 1}`}
                                className="size-20 rounded-md border bg-muted object-cover"
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    setDescribedIndex(null);
                                    onRemove(index);
                                }}
                                aria-label={`Remove image ${index + 1}`}
                                className="absolute top-1 right-1 rounded-full bg-background/80 p-0.5 text-foreground backdrop-blur transition hover:bg-background"
                            >
                                <X className="size-3.5" />
                            </button>
                            <button
                                id={altButtonId(index)}
                                type="button"
                                onClick={() =>
                                    setDescribedIndex(
                                        editing === index ? null : index,
                                    )
                                }
                                aria-label={
                                    isDescribed
                                        ? `Edit description of image ${index + 1}`
                                        : `Add description to image ${index + 1}`
                                }
                                aria-expanded={editing === index}
                                aria-controls={`${id}-description`}
                                className={cn(
                                    'absolute bottom-1 left-1 rounded px-1 text-[11px] leading-4 font-bold tracking-wide backdrop-blur transition',
                                    isDescribed
                                        ? 'bg-foreground text-background'
                                        : 'bg-background/80 text-foreground hover:bg-background',
                                )}
                                data-test="describe-image-button"
                            >
                                {isDescribed ? 'ALT' : '+ALT'}
                            </button>
                        </li>
                    );
                })}
            </ul>

            {editing !== null && (
                <div
                    id={`${id}-description`}
                    className="mt-2 rounded-md border bg-background p-2"
                >
                    <label
                        htmlFor={`${id}-description-input`}
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Description of image {editing + 1}
                    </label>
                    <textarea
                        id={`${id}-description-input`}
                        autoFocus
                        rows={2}
                        value={descriptions[editing] ?? ''}
                        onChange={(event) =>
                            onDescribe(editing, event.target.value)
                        }
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                event.stopPropagation();
                                finishDescribing();
                            }
                        }}
                        maxLength={maxDescriptionLength}
                        placeholder="Describe the image for people who can't see it"
                        className="mt-1 block field-sizing-content max-h-40 min-h-12 w-full resize-none bg-transparent text-sm leading-relaxed outline-none placeholder:text-muted-foreground"
                        data-test="image-description-input"
                    />
                    <div className="mt-1 flex items-center justify-end gap-3">
                        <span className="font-mono text-xs text-muted-foreground">
                            {(descriptions[editing] ?? '').length}/
                            {maxDescriptionLength}
                        </span>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={finishDescribing}
                        >
                            Done
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
