import { Image, Mic, Pin, Reply, SmilePlus } from 'lucide-react';
import type { ReactNode, RefObject } from 'react';
import { useEffect, useRef, useState } from 'react';
import { show as showMessageImage } from '@/actions/App/Http/Controllers/MessageImageController';
import MessageLinkPreviewController from '@/actions/App/Http/Controllers/MessageLinkPreviewController';
import { preloadEmojiGroups } from '@/components/chat/message-reactions';
import { VoiceNote } from '@/components/chat/voice-note';
import { CommitBody } from '@/components/commit-body';
import { objectUrlFor } from '@/components/commit-image-picker';
import { CommitImages } from '@/components/commit-images';
import type { ShownImage } from '@/components/commit-images';
import { ImageViewer } from '@/components/image-viewer';
import { LinkPreview } from '@/components/link-preview';
import { bodySegments } from '@/lib/code';
import { isReplySwipe, replyPull, replyThreshold } from '@/lib/reply-swipe';
import { cn, laneColor } from '@/lib/utils';
import type {
    BodySegment,
    Lane,
    Message,
    MessageImage,
    MessageReply,
} from '@/types';

/**
 * Who wrote a message, as a reply's ghost names them.
 */
export type Author = { name: string; lane: Lane; isMe: boolean };

/**
 * Links, mentions and code keep the primary bubble's colours, so they stay readable on it.
 */
const onPrimary =
    '[&_a]:text-primary-foreground [&_a]:underline [&_a]:decoration-primary-foreground/50 [&_a]:underline-offset-2 [&_.text-muted-foreground]:text-primary-foreground/70 [&_.fill-foreground]:fill-primary-foreground [&_blockquote]:border-neutral-200 [&_code]:bg-primary-foreground/15';

export function MessageText({
    message,
    isMine,
}: {
    message: Pick<Message, 'body' | 'body_segments' | 'mentions'>;
    isMine: boolean;
}) {
    const segments: BodySegment[] =
        message.body_segments ?? bodySegments(message.body);

    return (
        <CommitBody
            segments={segments}
            mentions={message.mentions ?? null}
            className={cn('flow-root leading-snug', { [onPrimary]: isMine })}
        />
    );
}

const isUnsent = (images: MessageImage[] | File[]): images is File[] =>
    images[0] instanceof File;

const downloadUrl = (image: ShownImage): string =>
    showMessageImage.url(image.id, { query: { download: 1 } });

/**
 * A message as the composer quotes it when replying to it.
 */
export function replyOf(message: Message): MessageReply {
    return {
        id: message.id,
        user_id: message.user_id,
        body: message.body,
        images_count: message.images?.length ?? 0,
        has_voice: (message.voice ?? null) !== null,
    };
}

export function timeOf(date: string): string {
    return new Date(date).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * The message a long press lifts above the thread: its voice note, or its text.
 */
export function LiftedMessage({
    message,
    isMine,
}: {
    message: Message;
    isMine: boolean;
}) {
    const voice = message.voice ?? null;

    if (voice === null && message.body === '') {
        return null;
    }

    return (
        <div
            className={cn(
                'w-fit max-w-full rounded-lg shadow-[0_12px_30px_-12px_rgb(0_0_0/0.45)]',
                voice === null
                    ? 'px-3.5 py-2 text-[15px] wrap-anywhere'
                    : 'p-2',
                isMine
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-foreground',
            )}
        >
            {voice === null ? (
                <MessageText message={message} isMine={isMine} />
            ) : (
                <VoiceNote voice={voice} />
            )}
        </div>
    );
}

export function MessageImages({ images }: { images: MessageImage[] | File[] }) {
    const [viewedIndex, setViewedIndex] = useState<number | null>(null);

    if (images.length === 0) {
        return null;
    }

    return (
        <div className="w-64 max-w-full sm:w-72 [&_img]:max-h-80 [&>div]:mt-0">
            {isUnsent(images) ? (
                <CommitImages
                    images={images.map((image) => ({
                        id: objectUrlFor(image),
                        url: objectUrlFor(image),
                        description: null,
                    }))}
                />
            ) : (
                <>
                    <CommitImages images={images} onOpen={setViewedIndex} />
                    <ImageViewer
                        images={images}
                        index={viewedIndex}
                        onView={setViewedIndex}
                        onClose={() => setViewedIndex(null)}
                        downloadUrl={downloadUrl}
                    />
                </>
            )}
        </div>
    );
}

export function MessageLinkCard({ message }: { message: Message }) {
    if (!message.link_preview) {
        return null;
    }

    return (
        <div className="w-64 max-w-full *:mt-1 sm:w-80">
            <LinkPreview
                refreshUrl={MessageLinkPreviewController.show.url(message.id)}
                preview={message.link_preview}
            />
        </div>
    );
}

/**
 * A message's first words on one line, or what it carries when it has none.
 */
export function snippetOf(reply: MessageReply): ReactNode {
    const body = reply.body
        .replace(/```[\s\S]*?(```|$)/g, ' code ')
        .replace(/`/g, '')
        .replace(/https?:\/\//gi, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (body !== '') {
        return body;
    }

    if (reply.has_voice) {
        return (
            <span className="inline-flex items-center gap-1">
                <Mic aria-hidden className="size-3.5" />
                Voice note
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1">
            <Image aria-hidden className="size-3.5" />
            {reply.images_count > 1 ? `${reply.images_count} images` : 'Image'}
        </span>
    );
}

/**
 * The message a reply answers, peeking out from behind it: who wrote it, in their lane colour, over its first line.
 * In the composer it sits flat, above the field.
 */
export function ReplyGhost({
    reply,
    author,
    side,
    isDraft = false,
    onJump,
}: {
    reply: MessageReply;
    author: Author;
    side: 'mine' | 'theirs';
    isDraft?: boolean;
    onJump?: (id: number) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onJump?.(reply.id)}
            disabled={onJump === undefined}
            aria-label={`In reply to ${author.name}: ${reply.body || (reply.has_voice ? 'a voice note' : 'an image')}`}
            className={cn(
                'flex max-w-full min-w-0 flex-col items-start rounded-lg px-3 text-left text-[13px] leading-snug transition-colors enabled:cursor-pointer',
                author.isMe
                    ? 'bg-primary/20 text-foreground/75'
                    : 'bg-muted/80 text-muted-foreground',
                isDraft
                    ? 'py-1.5'
                    : cn(
                          '-mb-3.5 scale-[.97] pt-1.5 pb-5 enabled:hover:bg-muted',
                          side === 'mine'
                              ? 'origin-bottom-right'
                              : 'origin-bottom-left',
                      ),
            )}
            data-test="reply-ghost"
        >
            <span
                className="max-w-full truncate text-xs font-semibold"
                style={{ color: laneColor(author.lane) }}
            >
                {author.name}
            </span>
            <span className="max-w-full truncate">{snippetOf(reply)}</span>
        </button>
    );
}

/**
 * Lets touch users pull a message to reply: someone else's to the right, their own to the left. Shows its reply
 * button on hover or focus.
 */
export function Repliable({
    isMine,
    onReply,
    children,
}: {
    isMine: boolean;
    onReply: (() => void) | null;
    children: ReactNode;
}) {
    const row = useRef<HTMLDivElement>(null);
    const [offset, setOffset] = useState(0);
    const reply = useRef(onReply);
    const isReplyable = onReply !== null;

    useEffect(() => {
        reply.current = onReply;
    });

    // The listeners are not passive, so a pull can keep the thread from scrolling under it, which React's can't.
    useEffect(() => {
        const element = row.current;

        if (element === null || !isReplyable) {
            return;
        }

        let start: { x: number; y: number } | null = null;
        let isPulling = false;
        let pull = 0;

        const reset = () => {
            start = null;
            isPulling = false;
            pull = 0;
            setOffset(0);
        };

        // A second finger anywhere on the screen cancels the pull, so a pinch or a stray touch never replies.
        const onTouchStart = (event: TouchEvent) => {
            const touch = event.touches[0];

            if (event.touches.length !== 1 || touch === undefined) {
                reset();

                return;
            }

            start = { x: touch.clientX, y: touch.clientY };
        };

        const onTouchMove = (event: TouchEvent) => {
            const touch = event.touches[0];

            if (event.touches.length !== 1) {
                reset();

                return;
            }

            if (start === null || touch === undefined) {
                return;
            }

            const dx = touch.clientX - start.x;
            const dy = touch.clientY - start.y;

            if (!isPulling) {
                if (!isReplySwipe(dx, dy)) {
                    start = null;

                    return;
                }

                isPulling = true;
            }

            event.preventDefault();
            pull = replyPull(dx, isMine);
            setOffset(pull);
        };

        const onTouchEnd = (event: TouchEvent) => {
            if (event.touches.length > 0) {
                reset();

                return;
            }

            if (isPulling && pull >= replyThreshold) {
                navigator.vibrate?.(8);
                reply.current?.();
            }

            reset();
        };

        element.addEventListener('touchstart', onTouchStart, { passive: true });
        element.addEventListener('touchmove', onTouchMove, { passive: false });
        element.addEventListener('touchend', onTouchEnd);
        element.addEventListener('touchcancel', reset);

        return () => {
            element.removeEventListener('touchstart', onTouchStart);
            element.removeEventListener('touchmove', onTouchMove);
            element.removeEventListener('touchend', onTouchEnd);
            element.removeEventListener('touchcancel', reset);
        };
    }, [isMine, isReplyable]);

    return (
        <div
            ref={row}
            className={cn('group/message flex w-full touch-pan-y', {
                'justify-end': isMine,
                'transition-transform duration-200 motion-reduce:transition-none':
                    offset === 0,
            })}
            style={
                offset > 0
                    ? {
                          transform: `translateX(${isMine ? -offset : offset}px)`,
                      }
                    : undefined
            }
        >
            {children}
        </div>
    );
}

/**
 * Pin, reply and react, just outside the end of the bubble they belong to and vertically centred on it, so they take
 * no room in the thread. They show on hover or focus, and stay while the reaction tray under the message is open. A
 * pinned message's pin always shows, nearest the bubble, so the others fade in beyond it without moving it, and only
 * maintainers can press it.
 */
export function BubbleActions({
    isMine,
    onReply,
    onReact,
    isReacting,
    reactRef,
    trayId,
    pin = null,
    isBelowReply = false,
}: {
    isMine: boolean;
    onReply: (() => void) | null;
    onReact: (() => void) | null;
    isReacting: boolean;
    reactRef: RefObject<HTMLButtonElement | null>;
    trayId: string;
    pin?: { isPinned: boolean; onToggle: (() => void) | null } | null;
    /**
     * The reply's quote overlaps the top 10px of the bubble, so the actions centre on what it leaves showing.
     */
    isBelowReply?: boolean;
}) {
    // The pointer is still on the pin right after pinning, so it doesn't act as Unpin until it leaves, as Follow does.
    const [isJustPinned, setIsJustPinned] = useState(false);
    const pinButton = useRef<HTMLButtonElement>(null);
    const isPinned = pin?.isPinned ?? false;
    const togglePin = pin?.onToggle ?? null;
    const isPinShown = isPinned || togglePin !== null;
    const pinLabel = isPinned ? (isJustPinned ? 'Pinned' : 'Unpin') : 'Pin';

    // Only the pointer moving off the pin counts as leaving it: the warning's overlay opening over a resting pointer
    // doesn't, so the pin stays safe through the warning too.
    useEffect(() => {
        if (!isJustPinned) {
            return;
        }

        const onPointerMove = (event: PointerEvent) => {
            const box = pinButton.current?.getBoundingClientRect();

            if (
                box === undefined ||
                event.clientX < box.left ||
                event.clientX > box.right ||
                event.clientY < box.top ||
                event.clientY > box.bottom
            ) {
                setIsJustPinned(false);
            }
        };

        document.addEventListener('pointermove', onPointerMove);

        return () => document.removeEventListener('pointermove', onPointerMove);
    }, [isJustPinned]);

    if (onReply === null && onReact === null && !isPinShown) {
        return null;
    }

    const button =
        'grid size-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
    const transient = cn(
        button,
        'opacity-0 transition-[color,background-color,opacity] group-hover/message:opacity-100 group-has-focus-visible/actions:opacity-100 group-data-open/actions:opacity-100 pointer-coarse:hidden',
    );

    return (
        <div
            data-open={isReacting ? '' : undefined}
            className={cn(
                'group/actions absolute top-1/2 flex -translate-y-1/2 items-center gap-0.5',
                isMine
                    ? 'right-full mr-1.5 flex-row-reverse'
                    : 'left-full ml-1.5',
                { 'top-[calc(50%+0.3125rem)]': isBelowReply },
            )}
            data-test="bubble-actions"
        >
            {isPinShown &&
                (togglePin === null ? (
                    <span
                        role="img"
                        aria-label="Pinned"
                        title="Pinned"
                        className="grid size-7 place-items-center text-muted-foreground"
                        data-test="pinned-glyph"
                    >
                        <Pin aria-hidden className="size-4 fill-current" />
                    </span>
                ) : (
                    <button
                        ref={pinButton}
                        type="button"
                        onClick={(event) => {
                            if (isPinned && isJustPinned) {
                                return;
                            }

                            // Only a pointer stays on it: the keyboard can unpin straight away.
                            setIsJustPinned(!isPinned && event.detail > 0);
                            togglePin();
                        }}
                        aria-label={pinLabel}
                        title={pinLabel}
                        aria-pressed={isPinned}
                        className={
                            isPinned
                                ? cn(
                                      button,
                                      // A bigger target for fingers, without changing how it looks or sits.
                                      'relative text-primary transition-colors hover:text-primary pointer-coarse:before:absolute pointer-coarse:before:-inset-2',
                                      {
                                          'cursor-default hover:bg-transparent':
                                              isJustPinned,
                                      },
                                  )
                                : transient
                        }
                        data-test="pin-button"
                    >
                        <Pin
                            aria-hidden
                            className={cn('size-4', {
                                'fill-current': isPinned,
                            })}
                        />
                    </button>
                ))}
            {onReply !== null && (
                <button
                    type="button"
                    onClick={onReply}
                    aria-label="Reply"
                    title="Reply"
                    className={transient}
                    data-test="reply-button"
                >
                    <Reply aria-hidden className="size-4" />
                </button>
            )}
            {onReact !== null && (
                <button
                    ref={reactRef}
                    type="button"
                    onClick={onReact}
                    onPointerEnter={() => void preloadEmojiGroups()}
                    aria-label="React"
                    title="React"
                    aria-expanded={isReacting}
                    aria-controls={trayId}
                    className={cn(transient, {
                        'bg-accent text-foreground': isReacting,
                    })}
                    data-test="react-button"
                >
                    <SmilePlus aria-hidden className="size-4" />
                </button>
            )}
        </div>
    );
}
