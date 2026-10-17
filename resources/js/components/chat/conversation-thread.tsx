import { ArrowDown } from 'lucide-react';
import type {
    ClipboardEvent,
    FormEvent,
    KeyboardEvent,
    ReactNode,
} from 'react';
import {
    memo,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import { MessageStatusGlyph } from '@/components/chat/message-status';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useThread } from '@/hooks/use-chat';
import {
    settleRequest,
    loadOlderMessages,
    markThreadRead,
    pingTyping,
    retryMessage,
    sendMessage,
} from '@/lib/chat';
import type { PendingMessage } from '@/lib/chat';
import {
    characterCount,
    cn,
    formatDayLabel,
    laneColor,
    limitCharacters,
    localDayKey,
} from '@/lib/utils';
import type { Message, PushAccess, User } from '@/types';

const maxLength = 2000;

const noMessages: Message[] = [];

const noPending: PendingMessage[] = [];

const countFrom = maxLength * 0.8;

const warnFrom = maxLength * 0.95;

type Bubble =
    | { kind: 'message'; message: Message }
    | { kind: 'pending'; pending: PendingMessage };

type Item =
    | { kind: 'day'; key: string; label: string }
    | { kind: 'head'; key: string; count: number }
    | {
          kind: 'group';
          key: string;
          isMine: boolean;
          bubbles: Bubble[];
      };

export function ConversationThread({
    partner,
    user,
    header,
    isVisible = true,
    isCompact = false,
    autoFocus = false,
    onDropped,
}: {
    partner: User;
    user: User;
    header: ReactNode;
    isVisible?: boolean;
    isCompact?: boolean;
    autoFocus?: boolean;
    /**
     * Called after dropping the partner's request, which takes it out of the inbox.
     */
    onDropped?: () => void;
}) {
    const thread = useThread(partner);
    const scroller = useRef<HTMLDivElement>(null);
    const headLine = useRef<HTMLDivElement>(null);
    const isPinned = useRef(true);
    const hasScrolledIn = useRef(false);
    const previousHeight = useRef<number | null>(null);
    const seenLatest = useRef(0);
    const [unseen, setUnseen] = useState(0);

    const messages = thread?.messages ?? noMessages;
    const pending = thread?.pending ?? noPending;
    const latestId = messages.at(-1)?.id ?? 0;
    const head = thread?.head ?? 0;
    const headUntil = thread?.headUntil ?? 0;
    const items = useMemo(
        () => itemsOf(messages, pending, head, headUntil, user),
        [messages, pending, head, headUntil, user],
    );
    const lastMine = useMemo(
        () => lastMineOf(messages, pending, user),
        [messages, pending, user],
    );
    const contentKey = `${latestId}:${pending.length}:${pending.map((item) => item.status).join()}`;

    useEffect(() => {
        if (isVisible && thread?.isLoaded && isPinned.current) {
            markThreadRead(partner);
        }
    }, [isVisible, latestId, thread?.isLoaded, partner]);

    useLayoutEffect(() => {
        const element = scroller.current;

        if (element === null || !thread?.isLoaded) {
            return;
        }

        if (!hasScrolledIn.current) {
            hasScrolledIn.current = true;
            seenLatest.current = latestId;
            headLine.current?.scrollIntoView({ block: 'center' });

            if (headLine.current === null) {
                element.scrollTop = element.scrollHeight;
            }

            return;
        }

        if (previousHeight.current !== null) {
            element.scrollTop += element.scrollHeight - previousHeight.current;
            previousHeight.current = null;

            return;
        }

        if (isPinned.current) {
            element.scrollTop = element.scrollHeight;
            seenLatest.current = latestId;
        } else {
            setUnseen(
                messages.filter(
                    (message) =>
                        message.id > seenLatest.current &&
                        message.user_id !== user.id,
                ).length,
            );
        }
        // Scrolling follows new content only, not every poll that changes nothing.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contentKey, thread?.isLoaded, messages[0]?.id]);

    const onScroll = () => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        isPinned.current =
            element.scrollHeight - element.scrollTop - element.clientHeight <
            48;

        if (isPinned.current) {
            seenLatest.current = latestId;
            setUnseen(0);

            if (isVisible) {
                markThreadRead(partner);
            }
        }

        if (
            element.scrollTop < 120 &&
            thread?.has_older &&
            !thread.isLoadingOlder
        ) {
            previousHeight.current = element.scrollHeight;
            void loadOlderMessages(partner);
        }
    };

    const jumpToLatest = () => {
        const element = scroller.current;

        element?.scrollTo({ top: element.scrollHeight, behavior: 'smooth' });
    };

    return (
        <section
            aria-label={`Conversation with ${partner.name}`}
            className="relative flex min-h-0 min-w-0 flex-1 flex-col"
        >
            {header}

            <div
                ref={scroller}
                onScroll={onScroll}
                className="flex min-h-0 flex-1 [scrollbar-width:thin] flex-col overflow-y-auto overscroll-contain"
                data-test="conversation-messages"
            >
                {thread === null || !thread.isLoaded ? (
                    <ThreadSkeleton isCompact={isCompact} />
                ) : (
                    <ol
                        aria-live="polite"
                        aria-relevant="additions"
                        className={cn(
                            'mt-auto flex flex-col pb-1',
                            isCompact
                                ? 'px-3 pt-3 text-sm'
                                : 'px-4 pt-4 text-[15px]',
                        )}
                    >
                        {thread.isLoadingOlder && (
                            <li className="flex justify-center py-2">
                                <Spinner className="size-4 text-muted-foreground" />
                            </li>
                        )}
                        {thread.messages.length === 0 &&
                            thread.pending.length === 0 && (
                                <li className="py-10 text-center font-mono text-[13px] text-muted-foreground">
                                    empty history. say hi to @{partner.username}
                                </li>
                            )}
                        {items.map((item) => (
                            <ThreadItem
                                key={item.key}
                                item={item}
                                readThrough={thread.partner_read_through_id}
                                readAt={thread.partner_read_at}
                                lastMine={lastMine}
                                user={user}
                                partner={partner}
                                headLine={headLine}
                                isCompact={isCompact}
                            />
                        ))}
                    </ol>
                )}
            </div>

            {unseen > 0 && (
                <button
                    type="button"
                    onClick={jumpToLatest}
                    className="absolute bottom-[calc(var(--composer-height,4rem)+2.5rem)] left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-primary px-3 py-1 font-mono text-xs font-medium text-primary-foreground tabular-nums shadow-lg"
                >
                    <ArrowDown aria-hidden className="size-3.5" />
                    {unseen} new
                </button>
            )}

            {(thread?.access ?? 'open') === 'open' ? (
                <Composer
                    partner={partner}
                    isCompact={isCompact}
                    autoFocus={autoFocus}
                    isPartnerTyping={thread?.partner_typing ?? false}
                />
            ) : (
                <AccessBar
                    access={thread?.access ?? 'open'}
                    partner={partner}
                    isCompact={isCompact}
                    onDropped={onDropped}
                />
            )}
        </section>
    );
}

function itemsOf(
    messages: Message[],
    pendingMessages: PendingMessage[],
    head: number,
    headUntil: number,
    user: User,
): Item[] {
    const bubbles: {
        bubble: Bubble;
        at: string;
        isMine: boolean;
        id: number;
    }[] = [
        ...messages.map((message) => ({
            bubble: { kind: 'message', message } as Bubble,
            at: message.created_at,
            isMine: message.user_id === user.id,
            id: message.id,
        })),
        ...pendingMessages.map((pending) => ({
            bubble: { kind: 'pending', pending } as Bubble,
            at: pending.created_at,
            isMine: true,
            id: Number.POSITIVE_INFINITY,
        })),
    ];
    const unread = messages.filter(
        (message) =>
            message.id > head &&
            message.id <= headUntil &&
            message.user_id !== user.id,
    );
    const firstUnread = messages.findIndex(
        (message) => message.id === unread[0]?.id,
    );
    const items: Item[] = [];
    let day: string | null = null;

    bubbles.forEach(({ bubble, at, isMine, id }, index) => {
        const dayKey = localDayKey(at);
        const last = items.at(-1);

        if (dayKey !== day) {
            day = dayKey;
            items.push({
                kind: 'day',
                key: `day-${dayKey}`,
                label: formatDayLabel(at),
            });
        }

        if (firstUnread > 0 && index === firstUnread) {
            items.push({ kind: 'head', key: 'head', count: unread.length });
        }

        const current = items.at(-1);

        if (
            current?.kind === 'group' &&
            current.isMine === isMine &&
            current === last
        ) {
            current.bubbles.push(bubble);

            return;
        }

        items.push({
            kind: 'group',
            key: `group-${bubble.kind === 'message' ? id : bubble.pending.client_id}`,
            isMine,
            bubbles: [bubble],
        });
    });

    return items;
}

/**
 * Only re-renders when its own messages, or the read position, change. Rows off screen skip layout and paint.
 */
const ThreadItem = memo(function ThreadItem({
    item,
    readThrough,
    readAt,
    lastMine,
    user,
    partner,
    headLine,
    isCompact,
}: {
    item: Item;
    readThrough: number;
    readAt: string | null;
    lastMine: string | null;
    user: User;
    partner: User;
    headLine: React.RefObject<HTMLDivElement | null>;
    isCompact: boolean;
}) {
    if (item.kind === 'day') {
        return (
            <li className="my-3 flex justify-center first:mt-0">
                <span className="inline-flex items-center gap-1.5 rounded bg-muted py-0.5 pr-2 pl-1.5 font-mono text-[11px] text-muted-foreground">
                    <span
                        aria-hidden
                        className="size-1.5 rounded-full bg-current opacity-60"
                    />
                    {item.label}
                </span>
            </li>
        );
    }

    if (item.kind === 'head') {
        return (
            <li>
                <div
                    ref={headLine}
                    className="my-3 flex items-center gap-2 font-mono text-[11px] font-medium text-primary after:h-px after:flex-1 after:bg-primary/50"
                >
                    HEAD · {item.count} new
                </div>
            </li>
        );
    }

    return (
        <li
            className={cn(
                'flex flex-col gap-0.5 [contain-intrinsic-size:auto_3rem] [content-visibility:auto] [&+&]:mt-2.5',
                {
                    'items-end': item.isMine,
                    'items-start': !item.isMine,
                },
            )}
        >
            {item.bubbles.map((bubble, index) => {
                const key =
                    bubble.kind === 'message'
                        ? bubble.message.client_id
                        : bubble.pending.client_id;
                const isLast = index === item.bubbles.length - 1;

                return (
                    <BubbleWithMeta
                        key={key}
                        bubble={bubble}
                        isMine={item.isMine}
                        isFirst={index === 0}
                        isLast={isLast}
                        isLatestMine={key === lastMine}
                        readThrough={readThrough}
                        readAt={readAt}
                        user={user}
                        partner={partner}
                        isCompact={isCompact}
                    />
                );
            })}
        </li>
    );
});

function lastMineOf(
    messages: Message[],
    pendingMessages: PendingMessage[],
    user: User,
): string | null {
    const pending = pendingMessages.at(-1);

    if (pending) {
        return pending.client_id;
    }

    return (
        messages.findLast((message) => message.user_id === user.id)
            ?.client_id ?? null
    );
}

function BubbleWithMeta({
    bubble,
    isMine,
    isFirst,
    isLast,
    isLatestMine,
    readThrough,
    readAt,
    user,
    partner,
    isCompact,
}: {
    bubble: Bubble;
    isMine: boolean;
    isFirst: boolean;
    isLast: boolean;
    isLatestMine: boolean;
    readThrough: number;
    readAt: string | null;
    user: User;
    partner: User;
    isCompact: boolean;
}) {
    const body =
        bubble.kind === 'message' ? bubble.message.body : bubble.pending.body;
    const status =
        bubble.kind === 'pending'
            ? bubble.pending.status
            : readThrough >= bubble.message.id
              ? 'pulled'
              : 'pushed';

    return (
        <>
            <div
                className={cn(
                    'w-fit max-w-[85%] rounded-[18px] px-3.5 py-2 wrap-anywhere whitespace-pre-wrap transition-[background-color,color,outline-color] duration-200',
                    isCompact ? 'max-w-[88%] px-3 py-1.5' : 'sm:max-w-[78%]',
                    isMine
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-foreground',
                    {
                        'rounded-tr-md': isMine && !isFirst,
                        'rounded-br-md': isMine && !isLast,
                        'rounded-tl-md': !isMine && !isFirst,
                        'rounded-bl-md': !isMine && !isLast,
                        'bg-transparent text-foreground outline-[1.5px] -outline-offset-[1.5px] outline-primary outline-dashed':
                            status === 'staged',
                        'bg-transparent text-foreground outline-[1.5px] -outline-offset-[1.5px] outline-destructive outline-solid':
                            status === 'rejected',
                    },
                )}
            >
                {body}
            </div>

            {status === 'rejected' ? (
                <MetaLine className="text-destructive">
                    <MessageStatusGlyph
                        status="rejected"
                        lane={user.lane}
                        partnerLane={partner.lane}
                    />
                    rejected ·
                    <button
                        type="button"
                        onClick={() =>
                            bubble.kind === 'pending' &&
                            retryMessage(partner, bubble.pending.client_id)
                        }
                        className="underline underline-offset-2"
                    >
                        retry
                    </button>
                </MetaLine>
            ) : isMine && isLatestMine ? (
                <MetaLine>
                    <MessageStatusGlyph
                        status={status}
                        lane={user.lane}
                        partnerLane={partner.lane}
                    />
                    <span>
                        {status}
                        {status === 'pulled' && readAt && ` ${timeOf(readAt)}`}
                    </span>
                </MetaLine>
            ) : (
                isLast &&
                bubble.kind === 'message' && (
                    <MetaLine>
                        <time dateTime={bubble.message.created_at}>
                            {timeOf(bubble.message.created_at)}
                        </time>
                    </MetaLine>
                )
            )}
        </>
    );
}

function MetaLine({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex h-[18px] items-center gap-1.5 px-1.5 font-mono text-[11px] whitespace-nowrap text-muted-foreground tabular-nums',
                className,
            )}
        >
            {children}
        </div>
    );
}

function timeOf(date: string): string {
    return new Date(date).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Counts characters once the message nears the limit, and refuses a paste that would cross it rather than
 * quietly cutting it short.
 */
function Composer({
    partner,
    isCompact,
    autoFocus,
    isPartnerTyping,
}: {
    partner: User;
    isCompact: boolean;
    autoFocus: boolean;
    isPartnerTyping: boolean;
}) {
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const errorTimer = useRef<number | undefined>(undefined);
    const trimmed = body.trim();
    const length = characterCount(body);
    const isCounting = length >= countFrom;

    useEffect(() => () => window.clearTimeout(errorTimer.current), []);

    const showError = (message: string | null) => {
        window.clearTimeout(errorTimer.current);
        setError(message);

        if (message !== null) {
            errorTimer.current = window.setTimeout(() => setError(null), 6000);
        }
    };

    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        if (trimmed === '') {
            return;
        }

        sendMessage(partner, trimmed);
        setBody('');
        showError(null);
        input.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            submit(event);
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        const pasted = event.clipboardData.getData('text');
        const field = event.currentTarget;
        const kept =
            characterCount(field.value.slice(0, field.selectionStart)) +
            characterCount(field.value.slice(field.selectionEnd));
        const total = kept + characterCount(pasted);

        if (total > maxLength) {
            event.preventDefault();
            showError(
                `fatal: paste rejected, message would be ${total.toLocaleString()}/${maxLength.toLocaleString()} chars`,
            );
        }
    };

    return (
        <>
            <div
                aria-live="polite"
                className={cn(
                    'flex h-6 shrink-0 items-center gap-2 font-mono text-xs text-muted-foreground',
                    isCompact ? 'px-3.5' : 'px-5',
                )}
                style={{ '--lane': laneColor(partner.lane) } as never}
            >
                <span className="flex min-w-0 flex-1 items-center gap-2">
                    {error !== null ? (
                        <span
                            role="alert"
                            className="truncate text-destructive"
                            title={error}
                            data-test="message-error"
                        >
                            {error}
                        </span>
                    ) : (
                        isPartnerTyping && (
                            <>
                                <span
                                    aria-hidden
                                    className="size-2.5 shrink-0 rounded-full border-2 border-dashed border-(--lane) motion-safe:animate-[spin_3s_linear_infinite]"
                                />
                                <span className="truncate font-sans">
                                    @{partner.username} is typing…
                                </span>
                            </>
                        )
                    )}
                </span>
                {isCounting && (
                    <span
                        aria-label={`${maxLength - length} characters left`}
                        className={cn('shrink-0 tabular-nums', {
                            'text-amber-700 dark:text-amber-400':
                                length >= warnFrom,
                            'text-destructive': length >= maxLength,
                        })}
                        data-test="message-counter"
                    >
                        {length.toLocaleString()}/{maxLength.toLocaleString()}
                    </span>
                )}
            </div>
            <form
                onSubmit={submit}
                className={cn(
                    'flex shrink-0 items-end gap-2 border-t',
                    isCompact ? 'p-2.5' : 'px-4 py-3',
                )}
            >
                <label
                    htmlFor={`message-${partner.username}`}
                    className="sr-only"
                >
                    Message @{partner.username}
                </label>
                <textarea
                    id={`message-${partner.username}`}
                    ref={input}
                    rows={1}
                    autoFocus={autoFocus}
                    value={body}
                    onChange={(event) => {
                        const value = limitCharacters(
                            event.target.value,
                            maxLength,
                        );

                        setBody(value);

                        if (
                            error !== null &&
                            characterCount(value) < maxLength
                        ) {
                            showError(null);
                        }

                        if (value.trim() !== '') {
                            pingTyping(partner);
                        }
                    }}
                    onPaste={onPaste}
                    onKeyDown={onKeyDown}
                    placeholder={`Message @${partner.username}`}
                    aria-invalid={error !== null || undefined}
                    className={cn(
                        'field-sizing-content max-h-40 min-h-10 min-w-0 flex-1 resize-none overflow-x-hidden rounded-[20px] border border-input bg-card px-4 py-2 text-base leading-snug outline-none placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none aria-invalid:border-destructive md:text-[15px]',
                        { 'min-h-9 py-1.5 md:text-sm': isCompact },
                    )}
                    data-test="message-input"
                />
                <Button
                    type="submit"
                    disabled={trimmed === ''}
                    className={cn(
                        'shrink-0 rounded-full',
                        isCompact ? 'h-9 min-w-16' : 'h-10 min-w-20',
                    )}
                >
                    Push
                </Button>
            </form>
        </>
    );
}

/**
 * Takes the composer's place when the user can't push: a fetched request to merge or drop, their own request
 * waiting to be merged, or a partner with remotes off. As tall as the composer, so nothing jumps.
 */
function AccessBar({
    access,
    partner,
    isCompact,
    onDropped,
}: {
    access: PushAccess;
    partner: User;
    isCompact: boolean;
    onDropped?: () => void;
}) {
    const [isSettling, setIsSettling] = useState(false);

    const settle = async (isMerging: boolean) => {
        setIsSettling(true);
        const isSettled = await settleRequest(partner, isMerging);
        setIsSettling(false);

        if (!isSettled) {
            toast.error('Could not update the request. Try again.');

            return;
        }

        if (!isMerging) {
            onDropped?.();
        }
    };

    return (
        <>
            <div aria-hidden className="h-6 shrink-0" />
            <div
                className={cn(
                    'flex min-h-16 shrink-0 items-center gap-3 border-t',
                    isCompact ? 'px-3 py-2.5' : 'px-4 py-3',
                )}
                data-test={`access-${access}`}
            >
                {access === 'request' ? (
                    <>
                        <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                            <span className="font-mono text-foreground">
                                @{partner.username}
                            </span>{' '}
                            wants to push to you. Merge to reply, or drop it.
                            They won&apos;t be told.
                        </p>
                        <Button
                            variant="outline"
                            className="min-w-16 rounded-full"
                            disabled={isSettling}
                            onClick={() => void settle(false)}
                            data-test="drop-request"
                        >
                            Drop
                        </Button>
                        <Button
                            className="min-w-20 rounded-full"
                            disabled={isSettling}
                            onClick={() => void settle(true)}
                            data-test="merge-request"
                        >
                            Merge
                        </Button>
                    </>
                ) : (
                    <p className="min-w-0 flex-1 font-mono text-[13px] text-muted-foreground">
                        {access === 'waiting'
                            ? `pushed. waiting for @${partner.username} to merge your request`
                            : `@${partner.username} isn't taking remotes`}
                    </p>
                )}
            </div>
        </>
    );
}

function ThreadSkeleton({ isCompact }: { isCompact: boolean }) {
    return (
        <div
            aria-hidden
            className={cn(
                'mt-auto flex flex-col gap-2 motion-safe:animate-in motion-safe:delay-300 motion-safe:fade-in-0 motion-safe:fill-mode-backwards',
                isCompact ? 'p-3' : 'p-4',
            )}
        >
            <Skeleton className="h-9 w-48 rounded-[18px]" />
            <Skeleton className="h-9 w-64 self-end rounded-[18px]" />
            <Skeleton className="h-9 w-40 self-end rounded-[18px]" />
            <Skeleton className="h-9 w-56 rounded-[18px]" />
        </div>
    );
}
