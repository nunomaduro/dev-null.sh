import { Link } from '@inertiajs/react';
import { ArrowDown, Mic, X } from 'lucide-react';
import type {
    ClipboardEvent,
    FormEvent,
    KeyboardEvent,
    ReactNode,
} from 'react';
import {
    memo,
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
    MessageImages,
    MessageLinkCard,
    MessageText,
    BubbleActions,
    Repliable,
    ReplyGhost,
    replyOf,
    timeOf,
} from '@/components/chat/message-parts';
import type { Author } from '@/components/chat/message-parts';
import {
    ReactionChips,
    ReactionSheet,
    ReactionTray,
    useTouchReactions,
} from '@/components/chat/message-reactions';
import { MessageStatusGlyph } from '@/components/chat/message-status';
import { EventLine, typingText } from '@/components/chat/org';
import { PinnedBanner, usePinMessage } from '@/components/chat/pinned-messages';
import { VoiceNote, VoiceNoteComposer } from '@/components/chat/voice-note';
import {
    ImageDropOverlay,
    ImagePickerButton,
    ImagePickerPreviews,
} from '@/components/commit-image-picker';
import { LinkCardPicker } from '@/components/link-card-picker';
import { MentionTextarea } from '@/components/mention-textarea';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { useChat, useThread } from '@/hooks/use-chat';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useLinkCard } from '@/hooks/use-link-card';
import { moveCaretToEnd, useStash } from '@/hooks/use-stash';
import { useVoiceNote } from '@/hooks/use-voice-note';
import {
    settleRequest,
    loadMessage,
    loadOlderMessages,
    markThreadRead,
    pingTyping,
    retryMessage,
    sendMessage,
    setReplyingTo,
    targetKey,
    toggleReaction,
} from '@/lib/chat';
import type { ChatTarget, PendingMessage } from '@/lib/chat';
import { isBehindLatest } from '@/lib/chat-scroll';
import { formatCount } from '@/lib/numbers';
import { pullsOf, statusMessageOf } from '@/lib/pulls';
import { doubleTapReaction } from '@/lib/reactions';
import type { ReactionUsage } from '@/lib/reactions';
import { restoreScrollAnchor, scrollAnchorOf } from '@/lib/scroll-anchor';
import type { ScrollAnchor } from '@/lib/scroll-anchor';
import {
    characterCount,
    cn,
    formatDayLabel,
    laneColor,
    localDayKey,
} from '@/lib/utils';
import { show } from '@/routes/user';
import type {
    Message,
    MessageReply,
    OrgMember,
    PushAccess,
    User,
} from '@/types';

const maxLength = 2000;

/**
 * The thread scrolls from its bottom, so its scroll position is 0 at the latest message and negative above it.
 */
const isAtLatest = (scroller: HTMLElement): boolean => scroller.scrollTop > -48;

const noMessages: Message[] = [];

const noPending: PendingMessage[] = [];

const noUsage: ReactionUsage[] = [];

const countFrom = maxLength * 0.8;

const warnFrom = maxLength * 0.95;

type Bubble =
    | { kind: 'message'; message: Message }
    | { kind: 'pending'; pending: PendingMessage };

type Item =
    | { kind: 'day'; key: string; label: string }
    | { kind: 'head'; key: string; count: number }
    | { kind: 'event'; key: string; message: Message }
    | {
          kind: 'group';
          key: string;
          isMine: boolean;
          authorId: string;
          author: User | null;
          bubbles: Bubble[];
      };

/**
 * Who has pulled up to where: the partner in a conversation, or each member in an org.
 */
type Pulls = {
    readThrough: number;
    readAt: string | null;
    members: OrgMember[];
};

export function ConversationThread({
    target,
    user,
    header,
    isVisible = true,
    isCompact = false,
    autoFocus = false,
    onDropped,
}: {
    target: ChatTarget;
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
    const thread = useThread(target);
    const usage = useChat().reactionUsage ?? noUsage;
    const isOrg = target.kind === 'org';
    // Renaming an org updates the thread's target, not the one this was opened with.
    const current = thread?.target ?? target;
    const scroller = useRef<HTMLDivElement>(null);
    const list = useRef<HTMLOListElement>(null);
    const headLine = useRef<HTMLDivElement>(null);
    const isPinned = useRef(true);
    const hasScrolledIn = useRef(false);
    const anchor = useRef<ScrollAnchor | null>(null);
    const restoredScrollTop = useRef<number | null>(null);
    const sentAnchor = useRef<ScrollAnchor | null>(null);
    const isFollowingSend = useRef(false);
    const isReplyResizing = useRef(false);
    const seenLatest = useRef(0);
    const [unseen, setUnseen] = useState(0);
    const [isBrowsingHistory, setIsBrowsingHistory] = useState(false);
    const [isScrolledBack, setIsScrolledBack] = useState(false);
    const [isJumping, setIsJumping] = useState(false);
    const jumpRequest = useRef<AbortController | null>(null);

    const messages = thread?.messages ?? noMessages;
    const pending = thread?.pending ?? noPending;
    const latestId = messages.at(-1)?.id ?? 0;
    const head = thread?.head ?? 0;
    const headUntil = thread?.headUntil ?? 0;
    const items = useMemo(
        () => itemsOf(messages, pending, head, headUntil, user, isOrg),
        [messages, pending, head, headUntil, user, isOrg],
    );
    const statusMessage = useMemo(
        () => statusMessageOf(messages, pending, user, isOrg),
        [messages, pending, user, isOrg],
    );
    const pulls = useMemo<Pulls>(
        () => ({
            readThrough: thread?.partner_read_through_id ?? 0,
            readAt: thread?.partner_read_at ?? null,
            members: (thread?.members ?? []).filter(
                (member) => member.user.id !== user.id,
            ),
        }),
        [
            thread?.partner_read_through_id,
            thread?.partner_read_at,
            thread?.members,
            user.id,
        ],
    );
    const contentKey = `${latestId}:${pending.length}:${pending.map((item) => item.status).join()}`;
    const isOpen = (thread?.access ?? 'open') === 'open';
    const draft = useMessageDraft(user, current);
    const replyingTo = thread?.replyingTo ?? null;
    const members = thread?.members;
    const pinnedMessages = thread?.pinned ?? noMessages;
    const canPin = isOrg && thread?.role === 'maintainer' && isOpen;
    // Polls hand over a new list whenever any reaction changes, so the bubbles only hear about the pins themselves.
    const pinnedKey = pinnedMessages.map((message) => message.id).join();
    const pinnedIds = useMemo(
        () => new Set(pinnedKey === '' ? [] : pinnedKey.split(',').map(Number)),
        [pinnedKey],
    );
    // Only who wrote the pinned messages matters for names, so a reaction on one doesn't re-render every row.
    const pinnedAuthorIds = [
        ...new Set(pinnedMessages.map((message) => message.user_id)),
    ]
        .toSorted()
        .join();
    const pinnedAuthors = useMemo(
        () => pinnedMessages.flatMap((message) => message.user ?? []),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [pinnedAuthorIds],
    );
    const pins = usePinMessage(target, user.id);
    const { pin, unpin } = pins;
    const togglePin = useCallback(
        (message: Message, isPinned: boolean) =>
            isPinned ? unpin(message) : pin(message),
        [pin, unpin],
    );

    const updateScrollPosition = useCallback(() => {
        setIsScrolledBack(
            scroller.current !== null &&
                isBehindLatest(scroller.current, messages),
        );
    }, [messages]);

    useLayoutEffect(updateScrollPosition, [updateScrollPosition, isVisible]);

    useLayoutEffect(() => {
        isReplyResizing.current = true;
    }, [replyingTo]);

    const settleReplyPreview = useCallback((isOpen: boolean) => {
        const element = scroller.current;
        isReplyResizing.current = false;

        if (element === null) {
            return;
        }

        if (!isPinned.current && anchor.current !== null) {
            restoreScrollAnchor(element, anchor.current);
            restoredScrollTop.current = element.scrollTop;
        }

        isPinned.current = isPinned.current || (!isOpen && isAtLatest(element));
        anchor.current ??= scrollAnchorOf(element);
    }, []);

    const authorOf = useCallback(
        (userId: string): Author => {
            if (userId === user.id) {
                return { name: 'You', lane: user.lane, isMe: true };
            }

            const person =
                current.kind === 'direct'
                    ? current.partner
                    : (members?.find((member) => member.user.id === userId)
                          ?.user ??
                      messages.find((message) => message.user_id === userId)
                          ?.user ??
                      pinnedAuthors.find((person) => person.id === userId));

            return {
                name: person?.name ?? 'Someone',
                lane: person?.lane ?? 0,
                isMe: false,
            };
        },
        [user, current, members, messages, pinnedAuthors],
    );

    useEffect(() => () => jumpRequest.current?.abort(), []);

    const jumpTo = useCallback(
        async (id: number) => {
            jumpRequest.current?.abort();
            const request = new AbortController();
            jumpRequest.current = request;
            anchor.current = null;
            sentAnchor.current = null;
            isFollowingSend.current = false;
            restoredScrollTop.current = null;
            isPinned.current = false;
            setIsBrowsingHistory(true);

            let bubble = scroller.current?.querySelector<HTMLElement>(
                `[data-message-id="${id}"]`,
            );

            if (!bubble) {
                setIsJumping(true);
                const found = await loadMessage(target, id, request.signal);

                if (request.signal.aborted) {
                    return;
                }

                // Let the store's last page render before locating the original bubble.
                if (found) {
                    await new Promise<void>((resolve) => {
                        requestAnimationFrame(() => resolve());
                    });
                }

                if (request.signal.aborted) {
                    return;
                }

                bubble = scroller.current?.querySelector<HTMLElement>(
                    `[data-message-id="${id}"]`,
                );
            }

            jumpRequest.current = null;
            setIsJumping(false);

            if (!bubble) {
                toast.error('Could not load this message. Try again.');
                return;
            }

            anchor.current = null;
            restoredScrollTop.current = null;
            bubble.scrollIntoView({ block: 'center', behavior: 'instant' });
            bubble.dataset.flash = '';
            window.setTimeout(() => delete bubble.dataset.flash, 1200);
        },
        [target],
    );

    const replyTo = useCallback(
        (reply: MessageReply) => {
            if (scroller.current !== null) {
                // Reuse the reading anchor across folds so rounded scroll positions cannot accumulate drift.
                if (isPinned.current) {
                    anchor.current = null;
                } else if (anchor.current === null) {
                    anchor.current = scrollAnchorOf(scroller.current);
                }
            }

            setReplyingTo(target, reply);
            draft.input.current?.focus({ preventScroll: true });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [targetKey(target)],
    );

    const react = useCallback(
        (messageId: number, emoji: string) => {
            void toggleReaction(target, messageId, emoji, user.id).then(
                (isSaved) => {
                    if (!isSaved) {
                        toast.error('Could not react. Try again.');
                    }
                },
            );
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [targetKey(target), user.id],
    );

    useEffect(() => {
        const markRead = () => {
            if (isVisible && thread?.isLoaded && isPinned.current) {
                markThreadRead(target);
            }
        };

        markRead();
        window.addEventListener('focus', markRead);
        document.addEventListener('visibilitychange', markRead);

        return () => {
            window.removeEventListener('focus', markRead);
            document.removeEventListener('visibilitychange', markRead);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isVisible, latestId, thread?.isLoaded, targetKey(target)]);

    useLayoutEffect(() => {
        const element = scroller.current;

        if (element === null || !thread?.isLoaded) {
            return;
        }

        if (!hasScrolledIn.current) {
            hasScrolledIn.current = true;
            seenLatest.current = latestId;
            headLine.current?.scrollIntoView({ block: 'center' });
            isPinned.current = isAtLatest(element);
            anchor.current = scrollAnchorOf(element);

            return;
        }

        if (sentAnchor.current !== null) {
            restoreScrollAnchor(element, sentAnchor.current);
            sentAnchor.current = null;
            anchor.current = scrollAnchorOf(element);
            isFollowingSend.current = element.scrollTop !== 0;
            element.scrollTo({
                top: 0,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'instant'
                    : 'smooth',
            });
            seenLatest.current = latestId;
        } else if (isFollowingSend.current) {
            // Confirming the same optimistic reply must not interrupt its scroll.
            seenLatest.current = latestId;
        } else if (isPinned.current) {
            element.scrollTop = 0;
            seenLatest.current = latestId;
        } else {
            if (anchor.current !== null) {
                restoreScrollAnchor(element, anchor.current);
                restoredScrollTop.current = element.scrollTop;
            }

            setUnseen(
                messages.filter(
                    (message) =>
                        message.id > seenLatest.current &&
                        message.user_id !== user.id &&
                        !message.event,
                ).length,
            );
        }
        // Scrolling follows new messages only, not every poll that changes nothing. Older messages need nothing: the
        // scroller is anchored at its bottom, so what loads above the visible rows never moves them.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contentKey, thread?.isLoaded]);

    const releaseScrollAnchor = useCallback(() => {
        // Let deliberate input own the viewport until the fold settles, including delayed automatic scroll events.
        anchor.current = null;
        sentAnchor.current = null;
        isFollowingSend.current = false;
        jumpRequest.current?.abort();
        jumpRequest.current = null;
        setIsJumping(false);
    }, []);

    // A row below the visible ones can still change height, as reactions or a link card arrive, and the scroller itself
    // shrinks as the composer grows or the keyboard opens. Anchored at its bottom, either would move what is being read,
    // so the top visible row is kept where it was.
    useEffect(() => {
        const element = scroller.current;
        const content = list.current;

        if (element === null || content === null) {
            return;
        }

        const observer = new ResizeObserver(() => {
            if (
                !isPinned.current &&
                !isFollowingSend.current &&
                anchor.current !== null
            ) {
                restoreScrollAnchor(element, anchor.current);
                restoredScrollTop.current = element.scrollTop;
            }

            updateScrollPosition();
        });

        const onWheel = (event: WheelEvent) => {
            if (event.ctrlKey || event.defaultPrevented || event.deltaY === 0) {
                return;
            }

            releaseScrollAnchor();

            if (isReplyResizing.current) {
                // WebKit can drop native wheel scrolling after a frame's scrollTop correction.
                event.preventDefault();
                const unit =
                    event.deltaMode === WheelEvent.DOM_DELTA_LINE
                        ? parseFloat(getComputedStyle(element).lineHeight)
                        : event.deltaMode === WheelEvent.DOM_DELTA_PAGE
                          ? element.clientHeight
                          : 1;
                element.scrollTop += event.deltaY * unit;
            }
        };

        observer.observe(content);
        observer.observe(element);
        element.addEventListener('wheel', onWheel, {
            passive: false,
        });
        element.addEventListener('touchmove', releaseScrollAnchor, {
            passive: false,
        });

        return () => {
            observer.disconnect();
            element.removeEventListener('wheel', onWheel);
            element.removeEventListener('touchmove', releaseScrollAnchor);
        };
    }, [releaseScrollAnchor, thread?.isLoaded, updateScrollPosition]);

    const onScroll = () => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        updateScrollPosition();

        // A correction during the reply fold is not an intentional scroll, even while it is close to the bottom.
        if (
            element.scrollTop === restoredScrollTop.current &&
            (isReplyResizing.current || !isAtLatest(element))
        ) {
            restoredScrollTop.current = null;

            return;
        }

        restoredScrollTop.current = null;
        isPinned.current = isAtLatest(element);
        if (element.scrollTop === 0) {
            isFollowingSend.current = false;
        }
        if (!isReplyResizing.current || anchor.current !== null) {
            anchor.current = scrollAnchorOf(element);
        }

        if (isPinned.current) {
            seenLatest.current = latestId;
            setUnseen(0);

            if (isVisible) {
                markThreadRead(target);
            }
        }

        // Older messages load a screen ahead, so scrolling up rarely reaches the top before they arrive.
        if (
            element.scrollHeight - element.clientHeight + element.scrollTop <
                element.clientHeight &&
            thread?.has_older &&
            !thread.isLoadingOlder
        ) {
            void loadOlderMessages(target);
        }
    };

    const jumpToLatest = () => {
        jumpRequest.current?.abort();
        jumpRequest.current = null;
        setIsJumping(false);
        setIsBrowsingHistory(false);
        setIsScrolledBack(false);
        anchor.current = null;
        sentAnchor.current = null;
        isFollowingSend.current = false;
        restoredScrollTop.current = null;
        isPinned.current = true;
        const element = scroller.current;

        element?.scrollTo({ top: 0, behavior: 'instant' });
    };

    return (
        <section
            aria-label={
                current.kind === 'direct'
                    ? `Conversation with ${current.partner.name}`
                    : `Org ${current.org.name ?? ''}`.trim()
            }
            className="relative flex min-h-0 min-w-0 flex-1 flex-col"
            {...(isOpen ? draft.attachments.dropzone : {})}
        >
            {header}

            {isOrg && (
                <PinnedBanner
                    messages={pinnedMessages}
                    user={user}
                    authorOf={authorOf}
                    boundary={scroller}
                    isCompact={isCompact}
                    usage={usage}
                    onReply={isOpen ? replyTo : null}
                    onReact={isOpen ? react : null}
                    onUnpin={canPin ? unpin : null}
                    onEmptied={() => {
                        if (!window.matchMedia('(pointer: coarse)').matches) {
                            draft.input.current?.focus({ preventScroll: true });
                        }
                    }}
                />
            )}
            {pins.dialog}

            <div
                ref={scroller}
                onScroll={onScroll}
                onScrollEnd={() => {
                    if (
                        scroller.current !== null &&
                        isAtLatest(scroller.current) &&
                        jumpRequest.current === null
                    ) {
                        setIsBrowsingHistory(false);
                    }
                }}
                onKeyDown={(event) => {
                    if (
                        !event.defaultPrevented &&
                        [
                            'ArrowUp',
                            'ArrowDown',
                            'PageUp',
                            'PageDown',
                            'Home',
                            'End',
                            ' ',
                        ].includes(event.key)
                    ) {
                        releaseScrollAnchor();
                    }
                }}
                onPointerDown={(event) => {
                    if (event.target === event.currentTarget) {
                        releaseScrollAnchor();
                    }
                }}
                className="flex min-h-0 flex-1 scrollbar-thin flex-col-reverse overflow-y-auto overscroll-contain [overflow-anchor:none]"
                data-test="conversation-messages"
            >
                {thread === null || !thread.isLoaded ? (
                    <ThreadSkeleton isCompact={isCompact} />
                ) : (
                    <ol
                        ref={list}
                        aria-live="polite"
                        aria-relevant="additions"
                        className={cn(
                            'flex flex-col pb-1',
                            isCompact
                                ? 'px-3 pt-3 text-sm'
                                : 'px-4 pt-4 text-[15px]',
                            // The pinned banner lies over the top, so the oldest message can still scroll clear of it.
                            pinnedMessages.length > 0 &&
                                (isCompact ? 'pt-12' : 'pt-13'),
                        )}
                    >
                        {thread.isLoadingOlder && (
                            <li className="flex justify-center py-2">
                                <Spinner className="size-4 text-muted-foreground" />
                            </li>
                        )}
                        {thread.messages.length === 0 &&
                            thread.pending.length === 0 &&
                            thread.access === 'open' && (
                                <li className="py-10 text-center font-mono text-[13px] text-muted-foreground">
                                    empty history.{' '}
                                    {target.kind === 'direct'
                                        ? `say hi to @${target.partner.username}`
                                        : 'say hi to the org'}
                                </li>
                            )}
                        {items.map((item) => (
                            <ThreadItem
                                key={item.key}
                                item={item}
                                pulls={pulls}
                                statusMessage={statusMessage}
                                user={user}
                                target={target}
                                headLine={headLine}
                                isCompact={isCompact}
                                authorOf={authorOf}
                                onJump={jumpTo}
                                onReply={isOpen ? replyTo : null}
                                usage={usage}
                                onReact={isOpen ? react : null}
                                pinnedIds={pinnedIds}
                                onPin={canPin ? togglePin : null}
                            />
                        ))}
                    </ol>
                )}
            </div>

            {(isBrowsingHistory || isScrolledBack || unseen > 0) && (
                <button
                    type="button"
                    onClick={jumpToLatest}
                    aria-label={
                        isBrowsingHistory || unseen === 0
                            ? 'Back to present'
                            : undefined
                    }
                    className="absolute bottom-[calc(var(--composer-height,4rem)+2.5rem)] left-1/2 flex min-h-9 -translate-x-1/2 items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-xs font-medium whitespace-nowrap text-primary-foreground shadow-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {isJumping ? (
                        <Spinner aria-hidden className="size-3.5" />
                    ) : (
                        <ArrowDown aria-hidden className="size-3.5" />
                    )}
                    {isBrowsingHistory || unseen === 0
                        ? 'Back to present'
                        : `${unseen} new`}
                    {isJumping && (
                        <span className="sr-only" role="status">
                            Loading original message
                        </span>
                    )}
                </button>
            )}

            {isOpen && draft.attachments.isDraggingFiles && (
                <ImageDropOverlay className="inset-2" />
            )}

            {isOpen ? (
                <Composer
                    target={current}
                    draft={draft}
                    members={isOrg ? thread?.members : undefined}
                    replyingTo={replyingTo}
                    replyAuthor={
                        replyingTo === null
                            ? null
                            : authorOf(replyingTo.user_id)
                    }
                    onSend={() => {
                        jumpRequest.current?.abort();
                        jumpRequest.current = null;
                        setIsJumping(false);
                        setIsBrowsingHistory(false);
                        sentAnchor.current =
                            scroller.current === null || isPinned.current
                                ? null
                                : scrollAnchorOf(scroller.current);
                        isPinned.current = true;
                    }}
                    onReplySettled={settleReplyPreview}
                    isCompact={isCompact}
                    autoFocus={autoFocus}
                    typing={
                        target.kind === 'org'
                            ? (thread?.typing ?? []).length > 0
                                ? typingText(thread?.typing ?? [])
                                : null
                            : thread?.partner_typing
                              ? `@${target.partner.username} is typing…`
                              : null
                    }
                />
            ) : (
                target.kind === 'direct' && (
                    <AccessBar
                        access={thread?.access ?? 'open'}
                        partner={target.partner}
                        isCompact={isCompact}
                        onDropped={onDropped}
                    />
                )
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
    isOrg: boolean,
): Item[] {
    const bubbles: {
        bubble: Bubble;
        at: string;
        isMine: boolean;
        author: string;
        id: number;
    }[] = [
        ...messages.map((message) => ({
            bubble: { kind: 'message', message } as Bubble,
            at: message.created_at,
            isMine: message.user_id === user.id,
            author: message.user_id,
            id: message.id,
        })),
        ...pendingMessages.map((pending) => ({
            bubble: { kind: 'pending', pending } as Bubble,
            at: pending.created_at,
            isMine: true,
            author: user.id,
            id: Number.POSITIVE_INFINITY,
        })),
    ];
    const unread = messages.filter(
        (message) =>
            message.id > head &&
            message.id <= headUntil &&
            message.user_id !== user.id &&
            !message.event,
    );
    const firstUnread = messages.findIndex(
        (message) => message.id === unread[0]?.id,
    );
    const items: Item[] = [];
    let day: string | null = null;

    bubbles.forEach(({ bubble, at, isMine, author, id }, index) => {
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

        if (bubble.kind === 'message' && bubble.message.event) {
            items.push({
                kind: 'event',
                key: `event-${id}`,
                message: bubble.message,
            });

            return;
        }

        const current = items.at(-1);

        if (
            current?.kind === 'group' &&
            current.isMine === isMine &&
            current.authorId === author &&
            current === last
        ) {
            current.bubbles.push(bubble);

            return;
        }

        items.push({
            kind: 'group',
            key: `group-${bubble.kind === 'message' ? bubble.message.client_id : bubble.pending.client_id}`,
            isMine,
            authorId: author,
            author:
                isOrg && !isMine && bubble.kind === 'message'
                    ? (bubble.message.user ?? null)
                    : null,
            bubbles: [bubble],
        });
    });

    return items;
}

/**
 * Only re-renders when its own messages, or the read position, change.
 */
const ThreadItem = memo(function ThreadItem({
    item,
    pulls,
    statusMessage,
    user,
    target,
    headLine,
    isCompact,
    authorOf,
    onJump,
    onReply,
    usage,
    onReact,
    pinnedIds,
    onPin,
}: {
    item: Item;
    pulls: Pulls;
    statusMessage: string | null;
    user: User;
    target: ChatTarget;
    headLine: React.RefObject<HTMLDivElement | null>;
    isCompact: boolean;
    authorOf: (userId: string) => Author;
    onJump: (id: number) => void;
    onReply: ((reply: MessageReply) => void) | null;
    usage: ReactionUsage[];
    onReact: ((messageId: number, emoji: string) => void) | null;
    pinnedIds: Set<number>;
    /**
     * Pins or unpins a message, for maintainers only.
     */
    onPin: ((message: Message, isPinned: boolean) => void) | null;
}) {
    if (item.kind === 'day') {
        return (
            <li className="my-3 flex justify-center first:mt-0">
                <span className="inline-flex items-center gap-1.5 rounded-sm bg-muted py-0.5 pr-2 pl-1.5 font-mono text-[11px] text-muted-foreground">
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
                    HEAD · {formatCount(item.count)} new
                </div>
            </li>
        );
    }

    if (item.kind === 'event') {
        return <EventLine message={item.message} user={user} />;
    }

    return (
        <li
            className={cn('flex flex-col gap-0.5 [&+&]:mt-2.5', {
                'items-end': item.isMine,
                'items-start': !item.isMine,
            })}
        >
            {item.author !== null && (
                <Link
                    href={show(item.author)}
                    className="group flex max-w-full min-w-0 items-center gap-1.5 rounded-sm px-1.5 pb-0.5 text-xs text-muted-foreground"
                    title={item.author.name}
                >
                    <UserAvatar
                        user={item.author}
                        className="size-4 ring-1 ring-(--lane)"
                        style={
                            {
                                '--lane': laneColor(item.author.lane),
                            } as React.CSSProperties
                        }
                    />
                    <span className="truncate font-medium text-foreground group-hover:underline">
                        {item.author.name}
                    </span>
                </Link>
            )}
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
                        isLast={isLast}
                        showsStatus={key === statusMessage}
                        pulls={pulls}
                        user={user}
                        target={target}
                        isCompact={isCompact}
                        authorOf={authorOf}
                        onJump={onJump}
                        onReply={onReply}
                        usage={usage}
                        onReact={onReact}
                        isPinned={
                            bubble.kind === 'message' &&
                            pinnedIds.has(bubble.message.id)
                        }
                        onPin={onPin}
                    />
                );
            })}
        </li>
    );
});

function BubbleWithMeta({
    bubble,
    isMine,
    isLast,
    showsStatus,
    pulls,
    user,
    target,
    isCompact,
    authorOf,
    onJump,
    onReply,
    usage,
    onReact,
    isPinned,
    onPin,
}: {
    bubble: Bubble;
    isMine: boolean;
    isLast: boolean;
    showsStatus: boolean;
    pulls: Pulls;
    user: User;
    target: ChatTarget;
    isCompact: boolean;
    authorOf: (userId: string) => Author;
    onJump: (id: number) => void;
    onReply: ((reply: MessageReply) => void) | null;
    usage: ReactionUsage[];
    onReact: ((messageId: number, emoji: string) => void) | null;
    isPinned: boolean;
    onPin: ((message: Message, isPinned: boolean) => void) | null;
}) {
    const [isReacting, setIsReacting] = useState(false);
    const [isTrayOpen, setIsTrayOpen] = useState(false);
    const trayId = useId();
    const reactButton = useRef<HTMLButtonElement>(null);
    const message =
        bubble.kind === 'message'
            ? bubble.message
            : { body: bubble.pending.body, mentions: null };
    const reply =
        bubble.kind === 'message'
            ? (bubble.message.reply_to ?? null)
            : bubble.pending.reply_to;
    const images =
        bubble.kind === 'message'
            ? (bubble.message.images ?? [])
            : bubble.pending.images;
    const voice =
        bubble.kind === 'message'
            ? (bubble.message.voice ?? null)
            : bubble.pending.voice;
    const { pulledBy, total } =
        bubble.kind === 'message' && target.kind === 'org'
            ? pullsOf(pulls.members, bubble.message)
            : { pulledBy: [], total: 0 };
    const status =
        bubble.kind === 'pending'
            ? bubble.pending.status
            : (
                    target.kind === 'org'
                        ? pulledBy.length > 0
                        : pulls.readThrough >= bubble.message.id
                )
              ? 'pulled'
              : 'pushed';
    const partnerLane =
        target.kind === 'direct'
            ? target.partner.lane
            : (pulledBy[0]?.lane ?? user.lane);

    const isOnPrimary = isMine && status !== 'staged' && status !== 'rejected';
    const side = isMine ? 'mine' : 'theirs';
    const replyToThis =
        onReply !== null && bubble.kind === 'message'
            ? () => onReply(replyOf(bubble.message))
            : null;
    // Only org messages pin. Contributors see the pin of a pinned one, and can't press it.
    const pin =
        target.kind !== 'org' || bubble.kind !== 'message'
            ? null
            : onPin !== null
              ? {
                    isPinned,
                    onToggle: () => onPin(bubble.message, isPinned),
                }
              : isPinned
                ? { isPinned, onToggle: null }
                : null;

    const reactions =
        bubble.kind === 'message' ? bubble.message.reactions : undefined;
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
        onReact !== null && bubble.kind === 'message'
            ? (emoji: string) => onReact(bubble.message.id, emoji)
            : null;
    const toggleTray =
        reactToThis === null
            ? null
            : () => setIsTrayOpen((isTrayOpen) => !isTrayOpen);
    const touch = useTouchReactions(
        reactToThis === null
            ? null
            : {
                  onLongPress: () => setIsReacting(true),
                  onDoubleTap: () => reactToThis(doubleTapReaction),
              },
    );

    const bubbleClass = cn(
        'relative w-fit max-w-full min-w-0 rounded-lg px-3.5 py-2 wrap-anywhere ring-primary ring-offset-2 ring-offset-background transition-[background-color,color,outline-color,box-shadow] duration-200 before:pointer-events-none before:absolute before:inset-0 before:rounded-[inherit] before:border-[1.5px] before:border-dashed before:border-primary before:opacity-0 before:transition-opacity before:duration-200 data-flash:ring-2 motion-reduce:transition-none motion-reduce:before:transition-none',
        isCompact && 'px-3 py-1.5',
        isMine
            ? 'bg-primary text-primary-foreground'
            : 'bg-muted text-foreground',
        {
            'bg-transparent text-foreground before:opacity-100':
                status === 'staged',
            'bg-transparent text-foreground outline-[1.5px] outline-offset-[-1.5px] outline-destructive outline-solid':
                status === 'rejected',
        },
    );
    const bubbleActions = (
        <BubbleActions
            isMine={isMine}
            onReply={replyToThis}
            onReact={toggleTray}
            isReacting={isTrayOpen}
            reactRef={reactButton}
            trayId={trayId}
            pin={pin}
            // Whichever part of the message comes first sits under the reply's quote.
            isBelowReply={
                reply !== null &&
                (images.length === 0 || (message.body === '' && voice === null))
            }
        />
    );

    return (
        <>
            <Repliable isMine={isMine} onReply={replyToThis}>
                <div
                    data-scroll-anchor={
                        bubble.kind === 'message'
                            ? bubble.message.client_id
                            : bubble.pending.client_id
                    }
                    className={cn(
                        'relative flex max-w-[85%] min-w-0 flex-col gap-1',
                        // Org bubbles leave room for a third action, the pin, whether or not it shows, so pushing a
                        // message or a pin arriving never rewraps one.
                        isCompact
                            ? target.kind === 'org'
                                ? 'max-w-[88%] pointer-fine:max-w-[calc(100%-5.5rem)]'
                                : 'max-w-[88%] pointer-fine:max-w-[calc(100%-3.5rem)]'
                            : cn('sm:max-w-[78%]', {
                                  'pointer-fine:max-sm:max-w-[calc(100%-5rem)]':
                                      target.kind === 'org',
                              }),
                        isMine ? 'items-end' : 'items-start',
                        {
                            '[-webkit-touch-callout:none] pointer-coarse:select-none':
                                reactToThis !== null,
                        },
                    )}
                    {...touch}
                >
                    {reply !== null && (
                        <ReplyGhost
                            reply={reply}
                            author={authorOf(reply.user_id)}
                            side={side}
                            onJump={onJump}
                        />
                    )}
                    {images.length > 0 && (
                        <div className="relative max-w-full">
                            <MessageImages images={images} />
                            {message.body === '' &&
                                voice === null &&
                                bubbleActions}
                        </div>
                    )}
                    {voice !== null && (
                        <div
                            data-message-id={
                                bubble.kind === 'message'
                                    ? bubble.message.id
                                    : undefined
                            }
                            className={cn(
                                bubbleClass,
                                isCompact ? 'p-1.5' : 'p-2',
                            )}
                        >
                            <VoiceNote voice={voice} />
                            {bubbleActions}
                        </div>
                    )}
                    {message.body !== '' && (
                        <div
                            data-message-id={
                                bubble.kind === 'message'
                                    ? bubble.message.id
                                    : undefined
                            }
                            className={bubbleClass}
                        >
                            <MessageText
                                message={message}
                                isMine={isOnPrimary}
                            />
                            {bubbleActions}
                        </div>
                    )}
                    {bubble.kind === 'message' && (
                        <MessageLinkCard message={bubble.message} />
                    )}
                    {reactToThis !== null && (
                        <div className="-mt-1 w-full">
                            <ReactionTray
                                id={trayId}
                                isOpen={isTrayOpen}
                                onOpenChange={setIsTrayOpen}
                                trigger={reactButton}
                                usage={usage}
                                reacted={reacted}
                                hasReactions={(reactions?.length ?? 0) > 0}
                                isMine={isMine}
                                onReact={reactToThis}
                            />
                        </div>
                    )}
                    <ReactionChips
                        reactions={reactions}
                        userId={user.id}
                        isMine={isMine}
                        nameOf={(userId) => authorOf(userId).name}
                        onToggle={reactToThis}
                    />
                </div>
            </Repliable>

            {bubble.kind === 'message' && reactToThis !== null && (
                <ReactionSheet
                    isOpen={isReacting}
                    onOpenChange={setIsReacting}
                    usage={usage}
                    reacted={reacted}
                    isMine={isMine}
                    body={message.body}
                    preview={
                        <LiftedMessage
                            message={bubble.message}
                            isMine={isMine}
                        />
                    }
                    onReact={reactToThis}
                    pin={
                        pin?.onToggle
                            ? { isPinned, onToggle: pin.onToggle }
                            : null
                    }
                />
            )}

            {status === 'rejected' ? (
                <MetaLine className="text-destructive">
                    <MessageStatusGlyph
                        status="rejected"
                        lane={user.lane}
                        partnerLane={partnerLane}
                    />
                    rejected ·
                    <button
                        type="button"
                        onClick={() =>
                            bubble.kind === 'pending' &&
                            retryMessage(target, bubble.pending.client_id)
                        }
                        className="underline underline-offset-2"
                    >
                        retry
                    </button>
                </MetaLine>
            ) : isMine && showsStatus ? (
                <MetaLine>
                    <MessageStatusGlyph
                        status={status}
                        lane={user.lane}
                        partnerLane={partnerLane}
                    />
                    <span>
                        {status}
                        {status === 'pulled' &&
                            target.kind === 'direct' &&
                            pulls.readAt &&
                            ` ${timeOf(pulls.readAt)}`}
                        {pulledBy.length > 0 && ' by'}
                    </span>
                    {pulledBy.length > 0 && (
                        <PulledBy users={pulledBy} total={total} />
                    )}
                </MetaLine>
            ) : (
                isLast &&
                bubble.kind === 'message' && (
                    <MetaLine>
                        <time dateTime={bubble.message.created_at}>
                            {timeOf(bubble.message.created_at)}
                        </time>
                        {showsStatus && pulledBy.length > 0 && (
                            <>
                                <span>· pulled by</span>
                                <PulledBy users={pulledBy} total={total} />
                            </>
                        )}
                    </MetaLine>
                )
            )}
        </>
    );
}

/**
 * The members who pulled a message, as small avatars, or "everyone" once the whole org has.
 */
function PulledBy({ users, total }: { users: User[]; total: number }) {
    const names = users.map((person) => `@${person.username}`).join(', ');

    if (users.length === total && total > 3) {
        return (
            <span title={names} data-test="pulled-by">
                everyone
            </span>
        );
    }

    return (
        <span
            className="flex items-center"
            title={names}
            aria-label={names}
            data-test="pulled-by"
        >
            {users.slice(0, 5).map((person) => (
                <UserAvatar
                    key={person.id}
                    user={person}
                    pixels={32}
                    className="size-3.5 ring-1 ring-background not-first:-ml-1"
                />
            ))}
            {users.length > 5 && (
                <span className="ml-1">+{users.length - 5}</span>
            )}
        </span>
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

/**
 * The draft being written to a conversation: its body and images, kept per conversation across pages and the dock,
 * and the link chosen for its card.
 */
function useMessageDraft(user: User, target: ChatTarget) {
    const stash = useStash(user.id, `remote:${targetKey(target)}`);
    const attachments = useImageAttachments(
        stash.images,
        stash.descriptions,
        stash.updateImages,
        stash.key,
    );
    const linkCard = useLinkCard(stash.body);
    const input = useRef<HTMLTextAreaElement>(null);

    return { stash, attachments, linkCard, input };
}

type MessageDraftState = ReturnType<typeof useMessageDraft>;

/**
 * Keeps the quoted content in place while the reply bar closes, including when sending the reply.
 */
function ReplyPreview({
    reply,
    author,
    onCancel,
    onSettled,
    wasSent,
}: {
    reply: MessageReply | null;
    author: Author | null;
    onCancel: () => void;
    onSettled: (isOpen: boolean) => void;
    wasSent: boolean;
}) {
    const preview = useRef<HTMLDivElement>(null);
    const [shown, setShown] = useState<{
        reply: MessageReply;
        author: Author;
    } | null>(null);
    const isOpen = reply !== null && author !== null;

    useEffect(() => {
        let isCurrent = true;

        void Promise.allSettled(
            preview.current
                ?.getAnimations()
                .map((animation) => animation.finished) ?? [],
        ).then(() => {
            if (isCurrent) {
                onSettled(isOpen);
            }
        });

        return () => {
            isCurrent = false;
        };
    }, [isOpen, onSettled, reply]);

    if (
        isOpen &&
        (shown?.reply !== reply ||
            shown.author.name !== author.name ||
            shown.author.lane !== author.lane)
    ) {
        setShown({ reply, author });
    }

    return (
        <div
            ref={preview}
            aria-hidden={!isOpen}
            inert={!isOpen}
            className={cn(
                'grid',
                wasSent
                    ? 'transition-none'
                    : 'transition-[grid-template-rows,opacity,margin-bottom] duration-300 ease-in-out motion-reduce:transition-none',
                isOpen
                    ? 'grid-rows-[1fr] opacity-100'
                    : '-mb-2 grid-rows-[0fr] opacity-0',
            )}
        >
            <div className="min-h-0 overflow-hidden">
                {shown !== null && (
                    <div className="flex min-w-0 items-center gap-1.5">
                        <ReplyGhost
                            reply={shown.reply}
                            author={shown.author}
                            side="theirs"
                            isDraft
                        />
                        <button
                            type="button"
                            onClick={onCancel}
                            aria-label="Cancel reply"
                            title="Cancel reply (Esc)"
                            className="grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            data-test="cancel-reply"
                        >
                            <X aria-hidden className="size-4" />
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Counts characters once the message nears the limit, and refuses a paste that would cross it rather than
 * quietly cutting it short. Pasted or picked images go along with the message, and Escape lets go of a reply. A voice
 * note goes on its own: while one is recorded it takes the field's place, Push sends it, and Escape discards it.
 */
function Composer({
    target,
    members,
    draft,
    replyingTo,
    replyAuthor,
    isCompact,
    autoFocus,
    typing,
    onSend,
    onReplySettled,
}: {
    target: ChatTarget;
    members: OrgMember[] | undefined;
    draft: MessageDraftState;
    replyingTo: MessageReply | null;
    replyAuthor: Author | null;
    isCompact: boolean;
    autoFocus: boolean;
    typing: string | null;
    /**
     * Called as the user pushes, so the thread follows their own message down.
     */
    onSend: () => void;
    onReplySettled: (isOpen: boolean) => void;
}) {
    const id = `message-${targetKey(target).replace(':', '-')}`;
    const placeholder =
        target.kind === 'direct'
            ? `Message @${target.partner.username}`
            : `Message ${target.org.name ?? 'the org'}`;
    const lane = target.kind === 'direct' ? target.partner.lane : 0;
    const { stash, attachments, linkCard, input } = draft;
    const body = stash.body;
    const [error, setError] = useState<string | null>(null);
    const [wasReplySent, setWasReplySent] = useState(false);
    const errorTimer = useRef<number | undefined>(undefined);
    const trimmed = body.trim();
    const length = characterCount(body);
    const isCounting = length >= countFrom;
    const hasImages = attachments.images.length > 0;
    const shownError = error ?? attachments.error;

    if (wasReplySent && replyingTo !== null) {
        setWasReplySent(false);
    }

    useEffect(() => () => window.clearTimeout(errorTimer.current), []);

    const showError = (message: string | null) => {
        window.clearTimeout(errorTimer.current);
        setError(message);

        if (message !== null) {
            errorTimer.current = window.setTimeout(() => setError(null), 6000);
        }
    };

    const voiceNote = useVoiceNote(showError);
    const isVoicing = voiceNote.state.status !== 'idle';

    const pushVoiceNote = async () => {
        const voice = await voiceNote.stop();

        if (voice === null) {
            return;
        }

        onSend();
        setWasReplySent(replyingTo !== null);
        setReplyingTo(target, null);
        sendMessage(target, {
            body: '',
            replyTo: replyingTo,
            images: [],
            descriptions: [],
            linkPreviewUrl: undefined,
            voice,
        });
        voiceNote.discard();
        showError(null);
        attachments.clearError();
    };

    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        if (isVoicing) {
            void pushVoiceNote();

            return;
        }

        if (attachments.isReading() || (trimmed === '' && !hasImages)) {
            return;
        }

        onSend();
        setWasReplySent(replyingTo !== null);
        setReplyingTo(target, null);
        sendMessage(target, {
            body: trimmed,
            replyTo: replyingTo,
            images: attachments.images,
            descriptions: attachments.descriptions,
            linkPreviewUrl: linkCard.isChosen ? linkCard.link : undefined,
        });
        stash.drop();
        linkCard.reset();
        showError(null);
        attachments.clearError();
        input.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.defaultPrevented) {
            return;
        }

        if (event.key === 'Escape' && replyingTo !== null) {
            event.preventDefault();
            setReplyingTo(target, null);

            return;
        }

        // On touch screens the keyboard's return key adds a line, and Push sends.
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing &&
            !window.matchMedia('(pointer: coarse)').matches
        ) {
            submit(event);
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        attachments.onPaste(event);

        if (event.defaultPrevented) {
            return;
        }

        const pasted = event.clipboardData.getData('text');
        const field = event.currentTarget;
        const kept =
            characterCount(field.value.slice(0, field.selectionStart)) +
            characterCount(field.value.slice(field.selectionEnd));
        const total = kept + characterCount(pasted);

        if (total > maxLength) {
            event.preventDefault();
            showError(
                `fatal: paste rejected, message would be ${formatCount(total)}/${formatCount(maxLength)} chars`,
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
                style={{ '--lane': laneColor(lane) } as never}
            >
                <span className="flex min-w-0 flex-1 items-center gap-2">
                    {shownError !== null ? (
                        <span
                            role="alert"
                            className="truncate text-destructive"
                            title={shownError}
                            data-test="message-error"
                        >
                            {shownError}
                        </span>
                    ) : (
                        typing !== null && (
                            <>
                                <span
                                    aria-hidden
                                    className="size-2.5 shrink-0 rounded-full border-2 border-dashed border-(--lane) motion-safe:animate-[spin_3s_linear_infinite]"
                                />
                                <span
                                    className="truncate font-sans"
                                    data-test="typing"
                                >
                                    {typing}
                                </span>
                            </>
                        )
                    )}
                </span>
                <LinkCardPicker
                    linkCard={linkCard}
                    className="-mr-1.5 h-6 max-w-40 shrink px-1.5 text-xs [&_svg]:size-3.5"
                />
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
                        {formatCount(length)}/{formatCount(maxLength)}
                    </span>
                )}
            </div>
            <form
                onSubmit={submit}
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && isVoicing) {
                        event.preventDefault();
                        voiceNote.discard();
                    }
                }}
                className={cn(
                    'flex shrink-0 flex-col gap-2 border-t',
                    isCompact ? 'p-2.5' : 'px-4 py-3',
                )}
            >
                <ReplyPreview
                    reply={replyingTo}
                    author={replyAuthor}
                    onSettled={onReplySettled}
                    wasSent={wasReplySent}
                    onCancel={() => {
                        setReplyingTo(target, null);
                        input.current?.focus();
                    }}
                />
                {hasImages && (
                    <div className="[&>div]:mt-0">
                        <ImagePickerPreviews
                            images={attachments.images}
                            descriptions={attachments.descriptions}
                            onDescribe={attachments.describe}
                            onRemove={attachments.remove}
                        />
                    </div>
                )}
                <div className="flex items-end gap-2">
                    {isVoicing ? (
                        <VoiceNoteComposer
                            voiceNote={voiceNote}
                            isCompact={isCompact}
                        />
                    ) : (
                        <>
                            <label htmlFor={id} className="sr-only">
                                {placeholder}
                            </label>
                            <ImagePickerButton
                                count={attachments.images.length}
                                reading={attachments.reading}
                                onPick={attachments.pick}
                                className={cn(
                                    '-mx-1 shrink-0',
                                    isCompact ? 'size-9' : 'size-10',
                                )}
                            />
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="-mx-1 inline-flex shrink-0">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={
                                                trimmed !== '' || hasImages
                                            }
                                            onClick={() =>
                                                void voiceNote.start()
                                            }
                                            aria-label="Record a voice note"
                                            className={cn(
                                                'text-muted-foreground',
                                                isCompact
                                                    ? 'size-9'
                                                    : 'size-10',
                                            )}
                                            data-test="record-voice-note"
                                        >
                                            <Mic className="size-5" />
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {trimmed !== '' || hasImages
                                        ? 'Voice notes go on their own'
                                        : 'Record a voice note (max. 1 min)'}
                                </TooltipContent>
                            </Tooltip>
                            <div className="relative min-w-0 flex-1">
                                <MentionTextarea
                                    id={id}
                                    ref={input}
                                    members={members}
                                    org={
                                        target.kind === 'org'
                                            ? target.org.id
                                            : undefined
                                    }
                                    rows={1}
                                    autoFocus={autoFocus}
                                    value={body}
                                    maxLength={maxLength}
                                    onValueChange={(value) => {
                                        stash.updateBody(value);
                                        showError(null);
                                        attachments.clearError();

                                        if (value.trim() !== '') {
                                            pingTyping(target);
                                        }
                                    }}
                                    onFocus={moveCaretToEnd}
                                    onPaste={onPaste}
                                    onKeyDown={onKeyDown}
                                    aria-invalid={
                                        shownError !== null || undefined
                                    }
                                    className={cn(
                                        'block field-sizing-content max-h-[min(10rem,calc(var(--visual-viewport-height,100dvh)/4))] min-h-10 w-full resize-none overflow-x-hidden rounded-lg border border-input bg-card px-4 py-2 text-base leading-snug outline-none focus-visible:border-primary focus-visible:outline-none aria-invalid:border-destructive md:text-[15px]',
                                        {
                                            'min-h-9 py-1.5 md:text-sm':
                                                isCompact,
                                        },
                                    )}
                                    data-test="message-input"
                                />
                                {body === '' && (
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'pointer-events-none absolute inset-x-px top-px truncate px-4 py-2 text-base leading-snug text-muted-foreground md:text-[15px]',
                                            { 'py-1.5 md:text-sm': isCompact },
                                        )}
                                    >
                                        {placeholder}
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                    <Button
                        type="submit"
                        disabled={
                            isVoicing
                                ? voiceNote.state.status === 'starting' ||
                                  voiceNote.state.status === 'encoding'
                                : attachments.reading ||
                                  (trimmed === '' && !hasImages)
                        }
                        // Pushing keeps the keyboard open, so the next message can follow right away.
                        onPointerDown={(event) => event.preventDefault()}
                        className={cn(
                            'shrink-0',
                            isCompact ? 'h-9 min-w-16' : 'h-10 min-w-20',
                        )}
                    >
                        Push
                    </Button>
                </div>
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
                            className="min-w-16"
                            disabled={isSettling}
                            onClick={() => void settle(false)}
                            data-test="drop-request"
                        >
                            Drop
                        </Button>
                        <Button
                            className="min-w-20"
                            disabled={isSettling}
                            onClick={() => void settle(true)}
                            data-test="merge-request"
                        >
                            Merge
                        </Button>
                    </>
                ) : (
                    <p className="min-w-0 flex-1 font-mono text-[13px] text-muted-foreground">
                        {
                            {
                                waiting: `pushed. waiting for @${partner.username} to merge your request`,
                                restricted: `@${partner.username} doesn't follow you, so you can't push to them yet`,
                                closed: `@${partner.username} isn't taking remotes`,
                                open: '',
                                request: '',
                            }[access]
                        }
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
            <Skeleton className="h-9 w-48 rounded-lg" />
            <Skeleton className="h-9 w-64 self-end rounded-lg" />
            <Skeleton className="h-9 w-40 self-end rounded-lg" />
            <Skeleton className="h-9 w-56 rounded-lg" />
        </div>
    );
}
