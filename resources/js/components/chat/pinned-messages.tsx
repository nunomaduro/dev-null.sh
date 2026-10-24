import {
    ChevronDown,
    Image,
    Pin,
    PinOff,
    Reply,
    SmilePlus,
    X,
} from 'lucide-react';
import type { CSSProperties, RefObject } from 'react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import {
    LiftedMessage,
    MessageText,
    replyOf,
    snippetOf,
    timeOf,
} from '@/components/chat/message-parts';
import type { Author } from '@/components/chat/message-parts';
import {
    preloadEmojiGroups,
    ReactionChips,
    ReactionSheet,
    ReactionTray,
} from '@/components/chat/message-reactions';
import { VoiceNote } from '@/components/chat/voice-note';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { UserAvatar } from '@/components/user-avatar';
import { ChatRequestError, setMessagePinned, targetKey } from '@/lib/chat';
import type { ChatTarget } from '@/lib/chat';
import { withLeavingRows } from '@/lib/leaving-rows';
import type { ReactionUsage } from '@/lib/reactions';
import { readStorage, writeStorage } from '@/lib/storage';
import { cn, formatDayLabel, laneColor } from '@/lib/utils';
import type { Message, MessageReply, User } from '@/types';

const warningDismissedKeyFor = (userId: string): string =>
    `pin-warning-dismissed:${userId}`;

/**
 * On touch screens each action takes taps across 44px around its 28px look, spaced so those areas don't overlap.
 */
const action =
    'relative grid size-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none pointer-coarse:before:absolute pointer-coarse:before:-inset-2';

/**
 * What a message says in a line of plain text, for titles and labels.
 */
function textOf(message: Message): string {
    const images = message.images?.length ?? 0;

    if (message.body !== '') {
        return message.body;
    }

    if ((message.voice ?? null) !== null) {
        return 'Voice note';
    }

    return images > 1 ? `${images} images` : 'Image';
}

/**
 * Pinning and unpinning, for the thread's bubbles and the pinned panel. Pinning asks first, every time until the user
 * says not to, since everyone in the org sees what is pinned, whatever history they were given. Returns the dialog
 * that asks, to render once in the thread.
 */
export function usePinMessage(target: ChatTarget, userId: string) {
    const key = targetKey(target);
    const warningDismissedKey = warningDismissedKeyFor(userId);
    const [confirming, setConfirming] = useState<Message | null>(null);
    const [isDismissing, setIsDismissing] = useState(false);
    const checkboxId = useId();

    // Resolves to whether the server took it.
    const save = useCallback(
        (message: Message, isPinned: boolean) =>
            setMessagePinned(target, message, isPinned).then(
                () => true,
                (error: unknown) => {
                    toast.error(
                        error instanceof ChatRequestError &&
                            error.status === 403 &&
                            error.body.message
                            ? error.body.message
                            : isPinned
                              ? 'Could not pin. Try again.'
                              : 'Could not unpin. Try again.',
                    );

                    return false;
                },
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [key],
    );

    const pin = useCallback(
        (message: Message) => {
            if (readStorage(warningDismissedKey) === '1') {
                void save(message, true);

                return;
            }

            setIsDismissing(false);
            setConfirming(message);
        },
        [warningDismissedKey, save],
    );

    const unpin = useCallback(
        (message: Message) => save(message, false),
        [save],
    );

    const confirm = () => {
        if (confirming === null) {
            return;
        }

        if (isDismissing) {
            writeStorage(warningDismissedKey, '1');
        }

        setConfirming(null);
        void save(confirming, true);
    };

    const dialog = (
        <Dialog
            open={confirming !== null}
            onOpenChange={(isOpen) => {
                if (!isOpen) {
                    setConfirming(null);
                }
            }}
        >
            <DialogContent
                className="flex max-h-[calc(var(--visual-viewport-height,100dvh)-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-md"
                data-test="pin-warning"
            >
                <div className="min-h-0 space-y-2 overflow-y-auto overscroll-contain px-5 pt-5 pb-4">
                    <DialogTitle className="pr-6 leading-snug">
                        Pin for the whole org?
                    </DialogTitle>
                    <DialogDescription>
                        Everyone in the org sees pinned messages, including
                        people added without Share history, who can&apos;t read
                        anything pushed before they joined.
                    </DialogDescription>
                    <div className="flex items-center gap-2.5 pt-2">
                        <Checkbox
                            id={checkboxId}
                            checked={isDismissing}
                            onCheckedChange={(checked) =>
                                setIsDismissing(checked === true)
                            }
                            data-test="pin-warning-dismiss"
                        />
                        <Label htmlFor={checkboxId} className="font-normal">
                            Don&apos;t show this again
                        </Label>
                    </div>
                </div>
                <div className="flex shrink-0 items-center justify-end gap-2 border-t px-5 py-3">
                    <Button
                        variant="ghost"
                        className="min-w-20"
                        onClick={() => setConfirming(null)}
                    >
                        Cancel
                    </Button>
                    <Button
                        autoFocus
                        className="min-w-28"
                        onClick={confirm}
                        data-test="pin-warning-confirm"
                    >
                        Pin message
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );

    return { pin, unpin, dialog };
}

/**
 * The org's pinned messages folded into one row over the top of the thread: the latest one, and how many there are.
 * It takes no height of its own and lies over the thread instead of pushing it down, so a pin arriving never moves
 * what is being read. Pressing it opens the panel listing them all. When the last one goes, the banner stays until
 * the panel has closed.
 */
export function PinnedBanner({
    messages,
    user,
    authorOf,
    boundary,
    isCompact,
    usage,
    onReply,
    onReact,
    onUnpin,
    onEmptied,
}: {
    messages: Message[];
    user: User;
    authorOf: (userId: string) => Author;
    /**
     * What the panel stays inside: the thread's scroller, between its header and the composer.
     */
    boundary: RefObject<HTMLElement | null>;
    isCompact: boolean;
    usage: ReactionUsage[];
    onReply: ((reply: MessageReply) => void) | null;
    onReact: ((messageId: number, emoji: string) => void) | null;
    onUnpin: ((message: Message) => Promise<boolean>) | null;
    /**
     * Called once the panel has closed because nothing is pinned any more, to hand focus on.
     */
    onEmptied: () => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [isPanelShown, setIsPanelShown] = useState(false);
    const [collisionBoundary, setCollisionBoundary] =
        useState<HTMLElement | null>(null);
    const [lastShown, setLastShown] = useState(messages);
    const headingId = useId();
    const isEmpty = messages.length === 0;

    if (!isEmpty && messages !== lastShown) {
        setLastShown(messages);
    }

    if (isEmpty && isOpen) {
        setIsOpen(false);
    }

    const shown = isEmpty ? lastShown : messages;
    const latest = shown.at(0);

    if (latest === undefined || (isEmpty && !isPanelShown)) {
        return null;
    }

    const author = authorOf(latest.user_id);
    const count = shown.length;

    return (
        <div className="relative z-10 h-0 shrink-0">
            <Popover
                open={isOpen}
                onOpenChange={(open) => {
                    setIsOpen(open);

                    if (open) {
                        setIsPanelShown(true);
                        setCollisionBoundary(boundary.current);
                    }
                }}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label={`${count === 1 ? 'One pinned message' : `${count} pinned messages`}. Latest from ${author.name}: ${textOf(latest)}`}
                        title={`${author.name}: ${textOf(latest)}`}
                        className={cn(
                            'group absolute inset-x-0 top-0 flex h-9 items-center gap-2 border-b bg-card/90 text-left backdrop-blur-md transition-colors hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset data-[state=open]:bg-card',
                            isCompact ? 'px-3' : 'px-4',
                        )}
                        data-test="pinned-banner"
                    >
                        <Pin
                            aria-hidden
                            className="size-3.5 shrink-0 fill-current text-primary"
                        />
                        <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
                            pinned ·{' '}
                            <span className="inline-block min-w-[2ch] tabular-nums">
                                {count}
                            </span>
                        </span>
                        {/* A new latest pin replaces the preview instead of shifting it as the name changes width. */}
                        <span
                            key={latest.id}
                            className="min-w-0 flex-1 truncate text-[13px]"
                        >
                            <span className="font-semibold">{author.name}</span>{' '}
                            <span className="text-muted-foreground">
                                {snippetOf(replyOf(latest))}
                            </span>
                        </span>
                        <ChevronDown
                            aria-hidden
                            className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                        />
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    sideOffset={0}
                    collisionBoundary={collisionBoundary}
                    aria-labelledby={headingId}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        (event.currentTarget as HTMLElement).focus();
                    }}
                    onCloseAutoFocus={(event) => {
                        setIsPanelShown(false);

                        // The banner is about to go, so focus can't go back to it.
                        if (isEmpty) {
                            event.preventDefault();
                            onEmptied();
                        }
                    }}
                    onEscapeKeyDown={(event) => {
                        // Escape in a message's reaction tray folds the tray, not the panel.
                        if (
                            event.target instanceof Element &&
                            event.target.closest('[data-reaction-tray]')
                        ) {
                            event.preventDefault();
                        }
                    }}
                    className="flex max-h-[min(var(--radix-popover-content-available-height),calc(var(--visual-viewport-height,100dvh)-2rem))] w-(--radix-popover-trigger-width) flex-col overflow-hidden rounded-none border-x-0 border-t-0 p-0 shadow-[0_12px_32px_-12px_rgb(0_0_0/0.35)]"
                    data-test="pinned-panel"
                >
                    <PinnedPanel
                        messages={messages}
                        count={count}
                        headingId={headingId}
                        user={user}
                        authorOf={authorOf}
                        isCompact={isCompact}
                        usage={usage}
                        onClose={() => setIsOpen(false)}
                        onReply={
                            onReply === null
                                ? null
                                : (reply) => {
                                      setIsOpen(false);
                                      onReply(reply);
                                  }
                        }
                        onReact={onReact}
                        onUnpin={onUnpin}
                    />
                </PopoverContent>
            </Popover>
        </div>
    );
}

/**
 * The panel's sticky header over its own scrolling list. An unpinned message collapses where it was before it goes.
 */
function PinnedPanel({
    messages,
    count,
    headingId,
    user,
    authorOf,
    isCompact,
    usage,
    onClose,
    onReply,
    onReact,
    onUnpin,
}: {
    messages: Message[];
    count: number;
    headingId: string;
    user: User;
    authorOf: (userId: string) => Author;
    isCompact: boolean;
    usage: ReactionUsage[];
    onClose: () => void;
    onReply: ((reply: MessageReply) => void) | null;
    onReact: ((messageId: number, emoji: string) => void) | null;
    onUnpin: ((message: Message) => Promise<boolean>) | null;
}) {
    const [rowsOf, setRowsOf] = useState(messages);
    const [rows, setRows] = useState(() => withLeavingRows([], messages));
    const [reactingTo, setReactingTo] = useState<number | null>(null);
    const handedFocus = useRef(new Map<number, HTMLElement | null>());

    if (rowsOf !== messages) {
        setRowsOf(messages);
        setRows((current) => withLeavingRows(current, messages));
    }

    return (
        <>
            <div
                className={cn(
                    'flex h-10 shrink-0 items-center gap-2 border-b pr-1.5',
                    isCompact ? 'pl-3' : 'pl-4',
                )}
            >
                <h2 id={headingId} className="text-sm font-semibold">
                    Pinned messages
                </h2>
                <span className="font-mono text-[11px] text-muted-foreground tabular-nums">
                    {count}
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close pinned messages"
                    title="Close"
                    className={cn(action, 'ml-auto')}
                >
                    <X aria-hidden className="size-4" />
                </button>
            </div>
            <ol className="min-h-0 flex-1 scrollbar-thin overflow-y-auto overscroll-contain">
                {rows.map(({ item: message, isLeaving }) => (
                    <PinnedItem
                        key={message.id}
                        message={message}
                        isLeaving={isLeaving}
                        user={user}
                        authorOf={authorOf}
                        isCompact={isCompact}
                        usage={usage}
                        isReacting={reactingTo === message.id}
                        onReactingChange={(isReacting) =>
                            setReactingTo((current) =>
                                isReacting
                                    ? message.id
                                    : current === message.id
                                      ? null
                                      : current,
                            )
                        }
                        onReply={onReply}
                        onReact={onReact}
                        onUnpin={onUnpin}
                        handedFocus={handedFocus}
                        onGone={() =>
                            setRows((current) =>
                                current.filter(
                                    (row) =>
                                        !row.isLeaving ||
                                        row.item.id !== message.id,
                                ),
                            )
                        }
                    />
                ))}
            </ol>
        </>
    );
}

/**
 * Moves focus off an unpinned message before it goes inert: to the next message's unpin button, or the one before.
 * Returns where it went.
 */
function focusNeighbour(button: HTMLElement): HTMLElement | null {
    const row = button.closest('li');
    const step = (
        from: Element | null | undefined,
        to: 'nextElementSibling' | 'previousElementSibling',
    ) => {
        let sibling = from?.[to];

        while (sibling?.hasAttribute('data-leaving')) {
            sibling = sibling[to];
        }

        return sibling;
    };

    const neighbour =
        (
            step(row, 'nextElementSibling') ??
            step(row, 'previousElementSibling')
        )?.querySelector<HTMLElement>('[data-unpin]') ?? null;

    neighbour?.focus({ preventScroll: true });

    return neighbour;
}

/**
 * One pinned message: who wrote it and when, its text, a voice note to play or how many images it has, and its
 * reactions. Its actions always show. Reacting brings up the quick reactions beside them, or on a touch screen opens
 * the sheet over the panel, which stays open behind it.
 */
function PinnedItem({
    message,
    isLeaving,
    user,
    authorOf,
    isCompact,
    usage,
    isReacting,
    onReactingChange,
    onReply,
    onReact,
    onUnpin,
    handedFocus,
    onGone,
}: {
    message: Message;
    isLeaving: boolean;
    user: User;
    authorOf: (userId: string) => Author;
    isCompact: boolean;
    usage: ReactionUsage[];
    isReacting: boolean;
    onReactingChange: (isReacting: boolean) => void;
    onReply: ((reply: MessageReply) => void) | null;
    onReact: ((messageId: number, emoji: string) => void) | null;
    onUnpin: ((message: Message) => Promise<boolean>) | null;
    /**
     * Where focus went as each message was unpinned here, kept until the unpin goes through.
     */
    handedFocus: RefObject<Map<number, HTMLElement | null>>;
    onGone: () => void;
}) {
    const reactButton = useRef<HTMLButtonElement>(null);
    const unpinButton = useRef<HTMLButtonElement>(null);
    const trayId = useId();
    const [isReactingOnTouch, setIsReactingOnTouch] = useState(false);
    const author = authorOf(message.user_id);
    const isMine = message.user_id === user.id;
    const images = message.images?.length ?? 0;
    const voice = message.voice ?? null;
    const reactions = message.reactions;
    const reacted = useMemo(
        () =>
            new Set(
                (reactions ?? [])
                    .filter((reaction) => reaction.user_id === user.id)
                    .map((reaction) => reaction.emoji),
            ),
        [reactions, user.id],
    );
    const reactToThis =
        onReact === null ? null : (emoji: string) => onReact(message.id, emoji);

    // A message back from a failed unpin takes focus back, unless the user has moved on from where it went.
    useEffect(() => {
        if (isLeaving) {
            return;
        }

        const neighbour = handedFocus.current.get(message.id);
        handedFocus.current.delete(message.id);

        if (neighbour !== undefined && document.activeElement === neighbour) {
            unpinButton.current?.focus({ preventScroll: true });
        }
    }, [isLeaving, handedFocus, message.id]);

    return (
        <li
            inert={isLeaving}
            data-leaving={isLeaving || undefined}
            className={cn(
                // The column can't grow past the panel, so a long name truncates instead of scrolling the list sideways.
                'group/pinned grid grid-cols-[minmax(0,1fr)] transition-[grid-template-rows,opacity] duration-300 ease-out',
                isLeaving ? 'grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr]',
            )}
            onTransitionEnd={(event) => {
                if (
                    isLeaving &&
                    event.target === event.currentTarget &&
                    event.propertyName === 'grid-template-rows'
                ) {
                    onGone();
                }
            }}
            data-test="pinned-message"
        >
            <div className={cn('min-h-0', { 'overflow-hidden': isLeaving })}>
                <div
                    className={cn(
                        'flex flex-col gap-1 border-t py-2.5 group-first/pinned:border-t-0',
                        isCompact ? 'px-3' : 'px-4',
                    )}
                >
                    <div className="flex min-w-0 items-center gap-2">
                        <UserAvatar
                            user={
                                message.user ?? {
                                    name: author.name,
                                    avatar_url: null,
                                }
                            }
                            pixels={40}
                            className="size-5 shrink-0 ring-1 ring-(--lane)"
                            style={
                                {
                                    '--lane': laneColor(author.lane),
                                } as CSSProperties
                            }
                        />
                        <span
                            className="min-w-0 truncate text-[13px] font-semibold"
                            title={author.name}
                        >
                            {author.name}
                        </span>
                        <time
                            dateTime={message.created_at}
                            className="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums"
                        >
                            {formatDayLabel(message.created_at)} ·{' '}
                            {timeOf(message.created_at)}
                        </time>
                        <span className="relative ml-auto flex shrink-0 items-center gap-0.5 pointer-coarse:gap-4">
                            {onReply !== null && (
                                <button
                                    type="button"
                                    onClick={() => onReply(replyOf(message))}
                                    aria-label="Reply"
                                    title="Reply"
                                    className={action}
                                    data-test="pinned-reply"
                                >
                                    <Reply aria-hidden className="size-4" />
                                </button>
                            )}
                            {reactToThis !== null && (
                                <button
                                    ref={reactButton}
                                    type="button"
                                    onPointerEnter={() =>
                                        void preloadEmojiGroups()
                                    }
                                    onClick={() => {
                                        if (
                                            window.matchMedia(
                                                '(pointer: coarse)',
                                            ).matches
                                        ) {
                                            setIsReactingOnTouch(true);
                                        } else {
                                            onReactingChange(!isReacting);
                                        }
                                    }}
                                    aria-label="React"
                                    title="React"
                                    aria-expanded={isReacting}
                                    aria-controls={trayId}
                                    className={cn(action, {
                                        'bg-accent text-foreground': isReacting,
                                    })}
                                    data-test="pinned-react"
                                >
                                    <SmilePlus aria-hidden className="size-4" />
                                </button>
                            )}
                            {reactToThis !== null && (
                                <span className="contents" data-reaction-tray>
                                    <ReactionTray
                                        id={trayId}
                                        isOpen={isReacting}
                                        onOpenChange={onReactingChange}
                                        trigger={reactButton}
                                        usage={usage}
                                        reacted={reacted}
                                        hasReactions={
                                            (reactions?.length ?? 0) > 0
                                        }
                                        isMine
                                        onReact={reactToThis}
                                        placement="beside"
                                    />
                                </span>
                            )}
                            {onUnpin !== null && (
                                <button
                                    ref={unpinButton}
                                    type="button"
                                    onClick={(event) => {
                                        handedFocus.current.set(
                                            message.id,
                                            focusNeighbour(event.currentTarget),
                                        );
                                        void onUnpin(message).then(
                                            (isUnpinned) => {
                                                if (isUnpinned) {
                                                    handedFocus.current.delete(
                                                        message.id,
                                                    );
                                                }
                                            },
                                        );
                                    }}
                                    aria-label="Unpin"
                                    title="Unpin"
                                    className={action}
                                    data-unpin
                                    data-test="pinned-unpin"
                                >
                                    <PinOff aria-hidden className="size-4" />
                                </button>
                            )}
                        </span>
                    </div>
                    {message.body !== '' && <PinnedText message={message} />}
                    {voice !== null && (
                        <div className="w-fit max-w-full rounded-lg bg-muted p-1.5 text-foreground">
                            <VoiceNote voice={voice} />
                        </div>
                    )}
                    {images > 0 && (
                        <span className="flex items-center gap-1 text-[13px] text-muted-foreground">
                            <Image aria-hidden className="size-3.5" />
                            {images > 1 ? `${images} images` : 'Image'}
                        </span>
                    )}
                    <ReactionChips
                        reactions={reactions}
                        userId={user.id}
                        isMine={false}
                        nameOf={(userId) => authorOf(userId).name}
                        onToggle={reactToThis}
                    />
                </div>
            </div>

            {reactToThis !== null && (
                <ReactionSheet
                    isOpen={isReactingOnTouch}
                    onOpenChange={setIsReactingOnTouch}
                    usage={usage}
                    reacted={reacted}
                    isMine={isMine}
                    body={message.body}
                    preview={
                        <LiftedMessage message={message} isMine={isMine} />
                    }
                    onReact={reactToThis}
                />
            )}
        </li>
    );
}

/**
 * Up to three lines of a pinned message, with the rest a press away. The toggle only shows when there is more.
 */
function PinnedText({ message }: { message: Message }) {
    const body = useRef<HTMLDivElement>(null);
    const [isLong, setIsLong] = useState(false);
    const [isExpanded, setIsExpanded] = useState(false);

    // Measured before paint, so the toggle is there from the first frame.
    useLayoutEffect(() => {
        const element = body.current;

        if (element === null || isExpanded) {
            return;
        }

        const measure = () =>
            setIsLong(element.scrollHeight > element.clientHeight + 1);
        const observer = new ResizeObserver(measure);

        measure();
        observer.observe(element);

        if (element.firstElementChild !== null) {
            observer.observe(element.firstElementChild);
        }

        return () => observer.disconnect();
    }, [isExpanded]);

    return (
        <>
            <div
                ref={body}
                className={cn('text-sm leading-snug wrap-anywhere', {
                    'max-h-[3lh] overflow-hidden': !isExpanded,
                })}
            >
                <MessageText message={message} isMine={false} />
            </div>
            {isLong && (
                <button
                    type="button"
                    onClick={() => setIsExpanded(!isExpanded)}
                    aria-expanded={isExpanded}
                    className="w-fit text-[13px] font-medium text-primary hover:underline"
                    data-test="pinned-show-more"
                >
                    {isExpanded ? 'Show less' : 'Show more'}
                </button>
            )}
        </>
    );
}
