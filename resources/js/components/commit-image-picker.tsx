import { ImagePlus, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { ActionTooltip } from '@/components/action-tooltip';
import type { ShownImage } from '@/components/commit-images';
import { ImageViewer } from '@/components/image-viewer';
import { Button } from '@/components/ui/button';
import {
    acceptedImageTypes,
    invalidImageMessage,
    maxImageBytes,
    maxImages,
} from '@/lib/read-picked-images';
import { cn } from '@/lib/utils';

export { maxImages } from '@/lib/read-picked-images';

export const maxDescriptionLength = 1000;

/**
 * Keeps the files the server would accept, and tells why any others were left out.
 */
export function pickImages(
    current: File[],
    picked: File[],
): { images: File[]; error: string | null } {
    const accepted = picked.filter(
        (file) =>
            acceptedImageTypes.includes(file.type) &&
            file.size <= maxImageBytes,
    );
    const images = [...current, ...accepted].slice(0, maxImages);

    if (accepted.length < picked.length) {
        return {
            images,
            error: invalidImageMessage,
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
            name.startsWith('image_descriptions'),
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
    reading = false,
    onPick,
    className,
}: {
    count: number;
    disabled?: boolean;
    reading?: boolean;
    onPick: (files: File[]) => Promise<void>;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);

    return (
        <>
            <input
                ref={inputRef}
                type="file"
                accept={acceptedImageTypes.join(',')}
                multiple
                hidden
                onChange={async (event) => {
                    const input = event.currentTarget;
                    const files = Array.from(input.files ?? []);
                    // Allow the same selection again even if this read is discarded.
                    input.value = '';
                    if (files.length === 0) return;

                    await onPick(files);
                }}
                data-test="commit-image-input"
            />
            <ActionTooltip label={`Attach up to ${maxImages} images`}>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    disabled={disabled || reading || count >= maxImages}
                    onClick={() => inputRef.current?.click()}
                    aria-label={reading ? 'Reading images' : 'Attach images'}
                    aria-busy={reading}
                    className={cn('text-muted-foreground', className)}
                    data-test="attach-images-button"
                >
                    <ImagePlus className="size-5" />
                </Button>
            </ActionTooltip>
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
    const [viewedIndex, setViewedIndex] = useState<number | null>(null);
    const urls = images.map(objectUrlFor);

    if (images.length === 0) {
        return null;
    }

    const editing =
        describedIndex !== null && describedIndex < images.length
            ? describedIndex
            : null;
    const activeViewedIndex =
        viewedIndex !== null && viewedIndex < images.length
            ? viewedIndex
            : null;
    const altButtonId = (index: number) => `${id}-alt-${index}`;
    const previewButtonId = (index: number) => `${id}-preview-${index}`;

    const closeViewer = () => {
        const indexToFocus = activeViewedIndex;
        setViewedIndex(null);
        if (indexToFocus !== null && typeof document !== 'undefined') {
            requestAnimationFrame(() => {
                document.getElementById(previewButtonId(indexToFocus))?.focus();
            });
        }
    };

    const finishDescribing = () => {
        if (editing !== null && typeof document !== 'undefined') {
            document.getElementById(altButtonId(editing))?.focus();
        }

        setDescribedIndex(null);
    };

    const downloadFilename = (_image: ShownImage, index: number): string => {
        const file = images[index];
        if (!file) {
            return `image-${index + 1}.png`;
        }

        if (file.name && file.name.includes('.')) {
            return file.name;
        }

        const subtype = file.type
            ? file.type.replace(/^image\//, '').toLowerCase()
            : '';
        const ext = subtype === 'jpeg' ? 'jpg' : subtype || 'png';
        const baseName = file.name || `image-${index + 1}`;

        return `${baseName}.${ext}`;
    };

    const shownImages: ShownImage[] = images.map((image, index) => ({
        id: image.name ? image.name.replace(/\.[^/.]+$/, '') : `${index + 1}`,
        url: urls[index],
        description: descriptions[index]?.trim() || null,
    }));

    return (
        <div className="mt-2">
            <ul className="flex gap-2" aria-label="Attached images">
                {urls.map((url, index) => {
                    const isDescribed = Boolean(descriptions[index]?.trim());

                    return (
                        <li key={url} className="relative">
                            <button
                                id={previewButtonId(index)}
                                type="button"
                                onClick={() => setViewedIndex(index)}
                                aria-label={`Preview attached image ${index + 1}`}
                                className="block cursor-pointer overflow-hidden rounded-md border bg-muted text-left transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                data-test="preview-image-button"
                            >
                                <img
                                    src={url}
                                    alt={`Attached image ${index + 1}`}
                                    className="size-20 object-cover"
                                />
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setDescribedIndex(null);
                                    if (viewedIndex === index) {
                                        setViewedIndex(null);
                                    } else if (
                                        viewedIndex !== null &&
                                        viewedIndex > index
                                    ) {
                                        setViewedIndex(viewedIndex - 1);
                                    }
                                    onRemove(index);
                                }}
                                aria-label={`Remove image ${index + 1}`}
                                className="absolute top-1 right-1 rounded-sm bg-background/80 p-0.5 text-foreground backdrop-blur transition hover:bg-background"
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

            <ImageViewer
                images={shownImages}
                index={activeViewedIndex}
                onView={setViewedIndex}
                onClose={closeViewer}
                downloadUrl={(image) => image.url}
                downloadFilename={downloadFilename}
            />

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
