import { useHttp } from '@inertiajs/react';
import { Eye, Link2 } from 'lucide-react';
import type { KeyboardEvent, RefObject } from 'react';
import { useId, useState } from 'react';
import CommitPreviewController from '@/actions/App/Http/Controllers/CommitPreviewController';
import { CommitBody } from '@/components/commit-body';
import { objectUrlFor } from '@/components/commit-image-picker';
import { CommitImages } from '@/components/commit-images';
import { Button } from '@/components/ui/button';
import { bodySegments, hasCodeBlock } from '@/lib/code';
import { hasModKey, useKeyLabels } from '@/lib/keyboard';
import { cn } from '@/lib/utils';
import type { BodySegment } from '@/types';

type Highlighted = { body: string; segments: BodySegment[] };

const isModifierEnter = (event: KeyboardEvent) =>
    event.key === 'Enter' && hasModKey(event);

const isPreviewShortcut = (event: KeyboardEvent) =>
    event.key.toLowerCase() === 'p' && event.shiftKey && hasModKey(event);

export type ComposerPreview = ReturnType<typeof useComposerPreview>;

/**
 * Switches a composer between writing and previewing its draft as the commit it becomes. The draft is rendered right
 * away, and its code blocks are highlighted once the server, the only place code is highlighted, sends them back.
 */
export function useComposerPreview({
    body,
    textareaRef,
    isSubmittable,
}: {
    body: string;
    textareaRef: RefObject<HTMLTextAreaElement | null>;
    isSubmittable: boolean;
}) {
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [highlighted, setHighlighted] = useState<Highlighted | null>(null);
    const previewId = useId();
    const request = useHttp<{ body: string }, { segments: BodySegment[] }>({
        body: '',
    });

    const draft = body.trim();
    const segments =
        highlighted?.body === draft
            ? highlighted.segments
            : bodySegments(draft);

    const highlight = async () => {
        if (!hasCodeBlock(segments) || highlighted?.body === draft) {
            return;
        }

        request.transform(() => ({ body: draft }));

        try {
            const response = await request.submit(
                CommitPreviewController.store(),
            );

            setHighlighted({ body: draft, segments: response.segments });
        } catch {
            // The preview keeps the code unhighlighted.
        }
    };

    const show = (previewing: boolean, moveFocus: boolean) => {
        setIsPreviewing(previewing);

        if (previewing) {
            void highlight();
        }

        if (moveFocus) {
            requestAnimationFrame(() =>
                (previewing
                    ? document.getElementById(previewId)
                    : textareaRef.current
                )?.focus(),
            );
        }
    };

    return {
        isPreviewing,
        draft,
        segments,
        previewId,
        toggle: (moveFocus = false) => show(!isPreviewing, moveFocus),
        close: () => setIsPreviewing(false),
        /**
         * Handles ⌘/Ctrl + Shift + P in the textarea, and tells whether it did.
         */
        handleShortcut: (event: KeyboardEvent<HTMLTextAreaElement>) => {
            if (!isPreviewShortcut(event)) {
                return false;
            }

            event.preventDefault();
            show(true, true);

            return true;
        },
        onPreviewKeyDown: (event: KeyboardEvent<HTMLDivElement>) => {
            if (isPreviewShortcut(event) || event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                show(false, true);
            }

            if (isModifierEnter(event) && isSubmittable) {
                event.preventDefault();
                event.currentTarget.closest('form')?.requestSubmit();
            }
        },
    };
}

export function PreviewToggle({
    preview,
    className,
}: {
    preview: ComposerPreview;
    className?: string;
}) {
    const keys = useKeyLabels();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => preview.toggle()}
            aria-pressed={preview.isPreviewing}
            aria-label="Preview"
            aria-keyshortcuts="Meta+Shift+P Control+Shift+P"
            title={`Preview (${keys.combo(keys.mod, keys.shift, 'P')})`}
            className={cn(
                'text-muted-foreground aria-pressed:bg-primary-soft aria-pressed:text-primary aria-pressed:hover:bg-primary-soft aria-pressed:hover:text-primary',
                className,
            )}
            data-test="preview-toggle"
        >
            <Eye className="size-5" />
        </Button>
    );
}

export function ComposerPreviewBody({
    preview,
    images,
    descriptions = [],
    url,
    className,
}: {
    preview: ComposerPreview;
    images: File[];
    descriptions?: string[];
    url: string | null;
    className?: string;
}) {
    return (
        <div
            id={preview.previewId}
            tabIndex={-1}
            role="region"
            aria-label="Preview"
            onKeyDown={preview.onPreviewKeyDown}
            className={cn('min-w-0 outline-none', className)}
            data-test="composer-preview"
        >
            {preview.segments.length > 0 ? (
                <CommitBody
                    segments={preview.segments}
                    mentions={null}
                    className="text-[15px]"
                />
            ) : (
                <p className="text-[15px] leading-relaxed text-muted-foreground">
                    Nothing to preview yet.
                </p>
            )}

            <CommitImages
                images={images.map((image, index) => ({
                    id: objectUrlFor(image),
                    url: objectUrlFor(image),
                    description: descriptions[index]?.trim() || null,
                }))}
            />

            {url && (
                <div
                    className="mt-3 flex items-center gap-3 rounded-lg border border-dashed px-3 py-2"
                    data-test="link-card-preview"
                >
                    <Link2
                        aria-hidden
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                    <div className="min-w-0">
                        <p className="truncate font-mono text-[13px]">
                            {url.replace(/^https?:\/\//i, '')}
                        </p>
                        <p className="text-sm leading-snug text-muted-foreground">
                            Unfurls into a link card after you commit.
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
