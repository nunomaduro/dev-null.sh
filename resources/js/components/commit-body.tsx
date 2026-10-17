import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CodeBlock } from '@/components/code-block';
import { LinkifiedText } from '@/components/linkified-text';
import { emojify } from '@/lib/emoji';
import { cn } from '@/lib/utils';
import type { BodySegment, Commit } from '@/types';

export function CommitBody({
    segments,
    mentions,
    className,
    isStatic = false,
}: {
    segments: BodySegment[];
    mentions: Commit['mentions'];
    className?: string;
    isStatic?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <div
            className={cn(
                'leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-wrap',
                className,
            )}
        >
            {segments.map((segment, index) => {
                switch (segment.type) {
                    case 'text':
                        return (
                            <LinkifiedText
                                key={index}
                                text={emojify(segment.text)}
                                mentions={mentions}
                                pasteisMode={Boolean(auth.pasteis)}
                                isStatic={isStatic}
                            />
                        );
                    case 'code':
                        return (
                            <code
                                key={index}
                                className="rounded-sm bg-muted px-1 py-0.5 font-mono text-[0.875em]"
                            >
                                {segment.code}
                            </code>
                        );
                    case 'block':
                        return (
                            <CodeBlock
                                key={index}
                                html={segment.html}
                                language={segment.language}
                                isCopyable={!isStatic}
                            />
                        );
                }
            })}
        </div>
    );
}

/**
 * A body too long to show whole in a list comes with its first lines as a preview, unfolded on demand.
 */
export function FoldedCommitBody({
    segments,
    previewSegments,
    mentions,
    className,
}: {
    segments: BodySegment[];
    previewSegments: BodySegment[] | null;
    mentions: Commit['mentions'];
    className?: string;
}) {
    const [isUnfolded, setIsUnfolded] = useState(false);
    const bodyRef = useRef<HTMLDivElement>(null);

    if (previewSegments === null) {
        return (
            <CommitBody
                segments={segments}
                mentions={mentions}
                className={className}
            />
        );
    }

    // The button goes away, so focus moves to what was unfolded: the commit card, which the keyboard shortcuts walk,
    // or the body itself inside a reply, which they don't.
    const unfold = (button: HTMLButtonElement) => {
        const card = button.closest<HTMLElement>('[data-reply], [data-commit]');

        setIsUnfolded(true);

        if (card?.matches('[data-commit]')) {
            card.focus({ preventScroll: true });
        } else {
            requestAnimationFrame(() =>
                bodyRef.current?.focus({ preventScroll: true }),
            );
        }
    };

    return (
        <div ref={bodyRef} tabIndex={-1} className="outline-none">
            <CommitBody
                segments={isUnfolded ? segments : previewSegments}
                mentions={mentions}
                className={className}
            />
            {!isUnfolded && (
                <button
                    type="button"
                    onClick={(event) => unfold(event.currentTarget)}
                    className="mt-0.5 text-[15px] font-medium text-primary hover:underline"
                    data-test="show-more"
                >
                    Show more
                </button>
            )}
        </div>
    );
}

export function CommitBodyExcerpt({
    segments,
    previewSegments = null,
    className,
}: {
    segments: BodySegment[];
    previewSegments?: BodySegment[] | null;
    className?: string;
}) {
    const bodyRef = useRef<HTMLDivElement>(null);
    const [isClamped, setIsClamped] = useState(false);

    useEffect(() => {
        const body = bodyRef.current;

        if (!body) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setIsClamped(body.scrollHeight > body.clientHeight),
        );
        observer.observe(body);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={bodyRef}
            className={cn(
                'overflow-hidden',
                className,
                previewSegments !== null
                    ? 'mask-b-from-[calc(100%-3rem)] mask-b-to-[calc(100%+0.75rem)]'
                    : isClamped && 'mask-b-from-60%',
            )}
        >
            <CommitBody
                segments={previewSegments ?? segments}
                mentions={null}
                className="text-[15px]"
                isStatic
            />
        </div>
    );
}
