import { Link, router, useHttp, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    CornerDownRight,
    MessageSquareReply,
    Trash2,
} from 'lucide-react';
import type { CSSProperties, FormEvent, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { toast } from 'sonner';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import CommitReplyController from '@/actions/App/Http/Controllers/CommitReplyController';
import { BodyCounter } from '@/components/body-counter';
import { FoldedCommitBody } from '@/components/commit-body';
import { CommitGiphy } from '@/components/commit-giphy';
import {
    ImageDropOverlay,
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import { CommitImages } from '@/components/commit-images';
import { CommitPastelButton } from '@/components/commit-pastel-button';
import { CommitSecondaryActions } from '@/components/commit-secondary-actions';
import { CommitStarButton } from '@/components/commit-star-button';
import {
    ComposerPreviewBody,
    PreviewToggle,
    useComposerPreview,
} from '@/components/composer-preview';
import { GiphyAttachmentPreview } from '@/components/giphy-attachment-preview';
import { GiphyPickerButton } from '@/components/giphy-picker-button';
import { RailDot } from '@/components/graph-rail';
import InputError from '@/components/input-error';
import { LinkCardPicker } from '@/components/link-card-picker';
import { LinkPreview } from '@/components/link-preview';
import { MentionTextarea } from '@/components/mention-textarea';
import { SecretWarning } from '@/components/secret-warning';
import { StashLabel } from '@/components/stash-label';
import { TimestampTooltip } from '@/components/timestamp-tooltip';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { UserPreview } from '@/components/user-preview';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { focusCommit } from '@/hooks/use-keyboard-shortcuts';
import { useLinkCard } from '@/hooks/use-link-card';
import { useNow } from '@/hooks/use-now';
import { useSecretWarning } from '@/hooks/use-secret-warning';
import { useSharedState } from '@/hooks/use-shared-state';
import { moveCaretToEnd, useHasStash, useStash } from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import { signIn } from '@/lib/auth';
import { maxBodyLength, minBodyLength } from '@/lib/commit';
import { commitStateKey } from '@/lib/commit-state';
import { giphyFormFields } from '@/lib/giphy';
import { hasModKey } from '@/lib/keyboard';
import {
    characterCount,
    cn,
    formatRelativeTime,
    isDeleted,
    laneOf,
} from '@/lib/utils';
import { show } from '@/routes/user';
import type {
    Commit,
    CommittedFlash,
    CursorPaginated,
    DeletedFlash,
    FlashToast,
    ThreadCommit,
} from '@/types';

const maxIndentDepth = 3;

type CreatedReply = {
    committed: CommittedFlash;
    toast?: FlashToast;
};

const replyFailedMessage = 'Could not send your reply. Try again.';

const parentUnavailableMessage =
    'That commit is no longer available to reply to.';

const changedThreadIds = new Set<string>();

function replyFailureMessage(response: { status: number; data: string }) {
    if (response.status !== 429) {
        return replyFailedMessage;
    }

    const { message } = JSON.parse(response.data) as { message: string };

    return message;
}

function useCommitsCountUpdate(): (change: number) => void {
    const { auth } = usePage().props;
    const [, setCommitsCount] = useSharedState(
        commitStateKey('commits_count', auth.user?.id ?? ''),
        auth.user?.commits_count ?? 0,
    );

    return (change) => setCommitsCount((count) => count + change);
}
const threadLayouts = {
    // Root replies line up with the commit text, and their elbow branches off the rail.
    root: '[--indent:0px] [--spine:-32px] sm:[--spine:-36px]',
    nested: '[--indent:32px] [--spine:12px] sm:[--indent:38px] sm:[--spine:14px]',
    flattened: '[--indent:0px]',
};

type Thread = {
    commit: ThreadCommit;
    count: number;
    replies: ThreadCommit[] | null;
    hasMore: boolean;
    isOpen: boolean;
    isPreview: boolean;
    isLoading: boolean;
    isReplying: boolean;
    toggle: () => void;
    loadMore: () => void;
    startReply: () => void;
    cancelReply: () => void;
    onReplied: () => void;
    onDeleted: () => void;
};

async function fetchReplies(
    commit: Pick<ThreadCommit, 'id'>,
    cursor: string | null,
    newest = false,
    limit = 50,
): Promise<CursorPaginated<ThreadCommit>> {
    const response = await fetch(
        CommitReplyController.index(commit, {
            query: { cursor, newest: newest ? 1 : 0, limit },
        }).url,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    );

    if (!response.ok) {
        throw new Error('Unable to load replies');
    }

    return (await response.json()) as CursorPaginated<ThreadCommit>;
}

async function fetchRepliesUntil(
    commit: ThreadCommit,
    minimum: number,
    newest = false,
): Promise<CursorPaginated<ThreadCommit>> {
    const page = await fetchReplies(commit, null, newest, newest ? 1 : 50);
    let data = page.data;
    let cursor = page.next_cursor;

    while (cursor !== null && data.length < minimum) {
        const next = await fetchReplies(commit, cursor, newest);
        data = [...data, ...next.data];
        cursor = next.next_cursor;
    }

    return { ...page, data, next_cursor: cursor };
}

export function useThread(
    commit: ThreadCommit,
    initialReplies?: CursorPaginated<ThreadCommit>,
    loadsReplies = false,
): Thread {
    const preview = !isDeleted(commit) ? commit.reply_preview : undefined;
    const isPreview = preview !== undefined;
    const initial = preview ?? initialReplies;
    const [replies, setReplies] = useState<ThreadCommit[] | null>(
        initial?.data ?? null,
    );
    const [nextCursor, setNextCursor] = useState<string | null>(
        initial?.next_cursor ?? null,
    );
    const [count, setCount] = useSharedState(
        commitStateKey('replies_count', commit.id),
        commit.replies_count ?? 0,
    );
    const [shouldLoadReplies] = useState(
        (loadsReplies && count > 0) ||
            (initial !== undefined && changedThreadIds.has(commit.id)),
    );
    const [isOpen, setIsOpen] = useState(
        initial !== undefined || shouldLoadReplies,
    );
    const [isLoading, setIsLoading] = useState(false);
    const [isReplying, setIsReplying] = useState(false);

    const [previousPreview, setPreviousPreview] = useState(preview);
    if (preview !== previousPreview) {
        setPreviousPreview(preview);
        if (preview !== undefined && (replies?.length ?? 0) <= 1) {
            setReplies(preview.data);
            setNextCursor(preview.next_cursor);
        }
    }

    const applyPage = (loaded: ThreadCommit[], cursor: string | null) => {
        setReplies(loaded);
        setNextCursor(cursor);

        if (cursor === null) {
            setCount(loaded.length);
        }
    };

    const reload = (extra = 0) => {
        const minimum =
            isPreview && (replies?.length ?? 0) <= 1
                ? 1
                : replies === null
                  ? 0
                  : nextCursor === null
                    ? Infinity
                    : replies.length + extra;

        setIsLoading(true);

        fetchRepliesUntil(commit, minimum, isPreview)
            .then((page) => applyPage(page.data, page.next_cursor))
            .catch(() => undefined)
            .finally(() => setIsLoading(false));
    };

    const commitId = commit.id;

    useEffect(() => {
        if (!shouldLoadReplies) {
            return;
        }

        let isCurrent = true;

        fetchReplies({ id: commitId }, null, isPreview, isPreview ? 1 : 50)
            .then((page) => {
                if (isCurrent) {
                    setReplies(page.data);
                    setNextCursor(page.next_cursor);
                }
            })
            .catch(() => undefined);

        return () => {
            isCurrent = false;
        };
    }, [commitId, shouldLoadReplies, isPreview]);

    const onRestored = useEffectEvent((restored: Commit) => {
        changedThreadIds.add(commit.id);
        const target =
            restored.parent_id === commit.id
                ? restored.parent
                : restored.quoted_commit;
        if (target?.replies_count !== undefined) {
            setCount(target.replies_count);
        }
        if (replies !== null) {
            reload(replies.some((reply) => reply.id === restored.id) ? 0 : 1);
        }
    });

    useEffect(
        () =>
            router.on('flash', (event) => {
                const restored = event.detail.flash.restored;
                if (
                    restored &&
                    (restored.parent_id === commit.id ||
                        restored.quoted_commit_id === commit.id)
                ) {
                    onRestored(restored);
                }
            }),
        [commit.id],
    );

    return {
        commit,
        count,
        replies,
        hasMore: replies !== null && nextCursor !== null,
        isOpen,
        isPreview,
        isLoading,
        isReplying,
        toggle: () => {
            if (!isOpen && replies === null) {
                reload();
            }

            setIsOpen(!isOpen);
        },
        loadMore: () => {
            if (isLoading || replies === null || nextCursor === null) {
                return;
            }

            setIsLoading(true);

            fetchReplies(commit, nextCursor, isPreview)
                .then((page) =>
                    applyPage([...replies, ...page.data], page.next_cursor),
                )
                .catch(() => undefined)
                .finally(() => setIsLoading(false));
        },
        startReply: () => setIsReplying(true),
        cancelReply: () => setIsReplying(false),
        onReplied: () => {
            changedThreadIds.add(commit.id);
            setIsReplying(false);
            setIsOpen(true);
            setCount((current) => current + 1);
            reload();
        },
        onDeleted: () => {
            changedThreadIds.add(commit.id);
            setCount((current) => Math.max(current - 1, 0));
            reload();
        },
    };
}

export function ThreadActions({
    thread,
    actions,
    hasKeyboardShortcuts = false,
}: {
    thread: Thread;
    actions?: ReactNode;
    hasKeyboardShortcuts?: boolean;
}) {
    const { auth } = usePage().props;
    const { commit } = thread;
    const hasStash = useHasStash(auth.user?.id ?? null, `reply-${commit.id}`);

    return (
        <>
            <div className="mt-1 -ml-2 flex flex-wrap items-center gap-0.5 text-[13px]">
                <div className="flex flex-wrap items-center gap-0.5 max-md:contents">
                    {actions}

                    {!isDeleted(commit) && (
                        <button
                            type="button"
                            onClick={
                                auth.user === null
                                    ? signIn
                                    : thread.isReplying
                                      ? thread.cancelReply
                                      : thread.startReply
                            }
                            aria-expanded={thread.isReplying}
                            aria-keyshortcuts={
                                hasKeyboardShortcuts && !thread.isReplying
                                    ? 'r'
                                    : undefined
                            }
                            title={
                                hasKeyboardShortcuts ? 'Reply (r)' : undefined
                            }
                            className="flex items-center gap-1.5 rounded-md px-2 py-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            data-test="reply-button"
                        >
                            <MessageSquareReply className="size-4" />
                            Reply
                            {hasStash && !thread.isReplying && (
                                <span
                                    title="You have an unsent reply here"
                                    className="font-mono text-[11px] opacity-70"
                                    data-test="reply-stash-hint"
                                >
                                    {'stash@{0}'}
                                </span>
                            )}
                        </button>
                    )}

                    {thread.count > 0 && !thread.isPreview && (
                        <button
                            type="button"
                            onClick={thread.toggle}
                            aria-expanded={thread.isOpen}
                            aria-keyshortcuts={
                                hasKeyboardShortcuts ? 'o' : undefined
                            }
                            title={
                                hasKeyboardShortcuts
                                    ? `${thread.isOpen ? 'Hide' : 'Show'} replies (o)`
                                    : undefined
                            }
                            className="flex items-center gap-1 rounded-md px-2 py-0.5 font-medium text-primary transition-colors hover:bg-primary-soft"
                            data-test="toggle-replies-button"
                        >
                            <ChevronDown
                                className={cn('size-4 transition-transform', {
                                    '-rotate-90': !thread.isOpen,
                                })}
                            />
                            {thread.isOpen
                                ? 'Hide replies'
                                : `${thread.count} ${thread.count === 1 ? 'reply' : 'replies'}`}
                        </button>
                    )}
                </div>

                {!isDeleted(commit) && (
                    <CommitSecondaryActions
                        commit={commit}
                        keyShortcut={hasKeyboardShortcuts ? 'y' : undefined}
                    />
                )}
            </div>

            {thread.isReplying && !isDeleted(commit) && (
                <ReplyComposer
                    parent={commit}
                    onCancel={thread.cancelReply}
                    onReplied={thread.onReplied}
                />
            )}
        </>
    );
}

export function ThreadSpine({
    thread,
    className,
}: {
    thread: Thread;
    className?: string;
}) {
    if (!thread.isOpen || !thread.replies?.length) {
        return null;
    }

    return (
        <span
            aria-hidden
            className={cn(
                'absolute bottom-0 left-1/2 border-l border-dashed',
                className,
            )}
            style={{ borderColor: laneOf(thread.commit) }}
            data-test="thread-spine"
        />
    );
}

const replyLink =
    'absolute left-[calc(var(--indent)+12px)] border-l border-dashed sm:left-[calc(var(--indent)+14px)]';

function ReplyLinks({
    previousLane,
    lane,
    hasNext,
}: {
    previousLane: string | null;
    lane: string;
    hasNext: boolean;
}) {
    return (
        <span aria-hidden>
            {previousLane === null ? (
                <span
                    className="absolute top-0 left-[calc(var(--spine)-0.5px)] h-[22px] w-[calc(var(--indent)-var(--spine)-3px)] rounded-bl-xl border-b border-l border-dashed border-(--lane) sm:h-6"
                    data-test="thread-elbow"
                />
            ) : (
                <span
                    className={cn(replyLink, 'top-0 h-1.5')}
                    style={{ borderColor: previousLane }}
                    data-test="reply-link"
                />
            )}
            {hasNext && (
                <span
                    className={cn(
                        replyLink,
                        'top-[38px] bottom-0 sm:top-[42px]',
                    )}
                    style={{ borderColor: lane }}
                />
            )}
        </span>
    );
}

export function ThreadReplies({
    thread,
    depth,
}: {
    thread: Thread;
    depth: number;
}) {
    if (!thread.isOpen) {
        return null;
    }

    const isFlattened = depth >= maxIndentDepth;
    const layout =
        depth === 0
            ? threadLayouts.root
            : isFlattened
              ? threadLayouts.flattened
              : threadLayouts.nested;

    return (
        <div
            className={cn('relative', layout)}
            style={{ '--lane': laneOf(thread.commit) } as CSSProperties}
            data-test="replies"
        >
            {thread.replies === null ? (
                <ReplySkeleton />
            ) : (
                <>
                    {thread.replies.map((reply, index, replies) => (
                        <ReplyItem
                            key={reply.id}
                            reply={reply}
                            parent={thread.commit}
                            depth={depth + 1}
                            links={
                                isFlattened ? null : (
                                    <ReplyLinks
                                        previousLane={
                                            index === 0
                                                ? null
                                                : laneOf(replies[index - 1])
                                        }
                                        lane={laneOf(reply)}
                                        hasNext={
                                            index < replies.length - 1 ||
                                            thread.hasMore
                                        }
                                    />
                                )
                            }
                            onDeleted={thread.onDeleted}
                        />
                    ))}
                    {thread.hasMore && (
                        <div className="relative pt-1.5 pl-(--indent)">
                            {!isFlattened && thread.replies.length > 0 && (
                                <span
                                    aria-hidden
                                    className={cn(
                                        replyLink,
                                        'top-0 h-5 sm:h-5.5',
                                    )}
                                    style={{
                                        borderColor: laneOf(
                                            thread.replies[
                                                thread.replies.length - 1
                                            ],
                                        ),
                                    }}
                                />
                            )}
                            <MoreRepliesButton
                                thread={thread}
                                isFlattened={depth + 1 > maxIndentDepth}
                            />
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function ReplySkeleton() {
    return (
        <div
            role="status"
            aria-label="Loading replies"
            className="flex gap-2 pt-2.5 pl-(--indent) motion-safe:animate-pulse sm:gap-2.5"
        >
            <span className="size-6 shrink-0 rounded-full bg-muted sm:size-7" />
            <span className="flex-1">
                <span className="my-1 block h-3 w-1/3 rounded bg-muted" />
                <span className="my-1.5 block h-3 w-3/4 rounded bg-muted" />
                <span className="mt-2.5 block h-3 w-24 rounded bg-muted" />
            </span>
        </div>
    );
}

function MoreRepliesButton({
    thread,
    isFlattened,
}: {
    thread: Thread;
    isFlattened: boolean;
}) {
    return (
        <button
            type="button"
            onClick={thread.loadMore}
            aria-disabled={thread.isLoading}
            className="-ml-2 flex max-w-full items-center gap-2 rounded-md px-2 py-0.5 text-[13px] text-muted-foreground transition-colors [overflow-anchor:none] hover:bg-accent hover:text-foreground aria-disabled:pointer-events-none sm:gap-2.5"
            data-test="more-replies-button"
        >
            <span
                aria-hidden
                className="relative flex size-6 shrink-0 items-center justify-center [--lane:var(--muted-foreground)] sm:size-7"
            >
                <RailDot />
            </span>
            {thread.isLoading ? (
                <span role="status">Loading replies…</span>
            ) : (
                <span className="truncate">
                    Show more
                    {isFlattened && !isDeleted(thread.commit) && (
                        <>
                            {' to '}
                            <span className="font-mono text-xs">
                                @{thread.commit.user.username}
                            </span>
                        </>
                    )}
                </span>
            )}
        </button>
    );
}

function ReplyItem({
    reply,
    parent,
    depth,
    links,
    onDeleted,
}: {
    reply: ThreadCommit;
    parent: ThreadCommit;
    depth: number;
    links: ReactNode;
    onDeleted: () => void;
}) {
    const thread = useThread(reply);

    if (isDeleted(reply)) {
        return (
            <article
                className="relative pt-2.5 pl-(--indent)"
                data-reply
                data-test="reply-tombstone"
            >
                {links}
                <div className="flex gap-2 sm:gap-2.5">
                    <div className="relative shrink-0">
                        <span
                            aria-hidden
                            className="block size-6 rounded-full border-2 border-dashed border-rail sm:size-7"
                        />
                        {depth < maxIndentDepth && (
                            <ThreadSpine
                                thread={thread}
                                className="top-7 sm:top-8"
                            />
                        )}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="flex min-h-6 items-center gap-1.5 text-sm text-muted-foreground sm:min-h-7">
                            <span className="font-mono text-xs">[deleted]</span>
                            This commit was deleted.
                        </p>
                        <ThreadActions thread={thread} />
                    </div>
                </div>

                <ThreadReplies thread={thread} depth={depth} />
            </article>
        );
    }

    return (
        <CommitReply
            reply={reply}
            parent={parent}
            depth={depth}
            links={links}
            thread={thread}
            onDeleted={onDeleted}
        />
    );
}

function CommitReply({
    reply,
    parent,
    depth,
    links,
    thread,
    onDeleted,
}: {
    reply: Commit;
    parent: ThreadCommit;
    depth: number;
    links: ReactNode;
    thread: Thread;
    onDeleted: () => void;
}) {
    const { auth } = usePage().props;
    const now = useNow();
    const [isConfirming, setIsConfirming] = useState(false);
    const request = useHttp<Record<string, never>, { deleted: DeletedFlash }>(
        {},
    );
    const updateCommitsCount = useCommitsCountUpdate();

    const avatarRef = useRef<HTMLAnchorElement>(null);

    const isOwn = reply.user_id === auth.user?.id;
    const isFlattened = depth > maxIndentDepth;
    const isQuote = reply.quoted_commit_id === parent.id;
    const permalink = CommitController.show({
        user: reply.user,
        commit: reply,
    });

    const deleteReply = async () => {
        try {
            const response = await request.submit(
                CommitReplyController.destroy({ commit: parent, reply }),
            );

            router.flushAll();
            updateCommitsCount(-1);
            onDeleted();
            router.flash(() => response);
        } catch {
            toast.error('Could not delete your reply. Try again.');

            return;
        }
    };

    return (
        <article
            className="relative pt-2.5 pl-(--indent)"
            data-reply
            data-test="reply"
        >
            {links}
            <div className="flex gap-2 sm:gap-2.5">
                <div className="relative shrink-0">
                    <UserPreview user={reply.user} anchor={avatarRef}>
                        <Link
                            ref={avatarRef}
                            href={show(reply.user)}
                            className="block rounded-full"
                        >
                            <UserAvatar
                                user={reply.user}
                                className="size-6 ring-2 ring-(--lane) ring-offset-2 ring-offset-card sm:size-7"
                                style={
                                    {
                                        '--lane': laneOf(reply),
                                    } as CSSProperties
                                }
                            />
                        </Link>
                    </UserPreview>
                    {depth < maxIndentDepth && (
                        <ThreadSpine
                            thread={thread}
                            className="top-7 sm:top-8"
                        />
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-1.5 text-sm">
                        <UserPreview
                            user={reply.user}
                            anchor={avatarRef}
                            className="flex min-w-0 items-baseline gap-1.5"
                        >
                            <Link
                                href={show(reply.user)}
                                className="truncate font-semibold hover:underline"
                            >
                                {reply.user.name}
                            </Link>
                            <Link
                                href={show(reply.user)}
                                tabIndex={-1}
                                className="hidden truncate font-mono text-xs text-muted-foreground sm:inline"
                            >
                                @{reply.user.username}
                            </Link>
                        </UserPreview>
                        {isQuote && (
                            <Link
                                href={permalink}
                                className="shrink-0 font-mono text-xs text-primary hover:underline"
                                data-test="quote-reply-label"
                            >
                                quoted
                            </Link>
                        )}
                        <span aria-hidden className="text-muted-foreground">
                            ·
                        </span>
                        <TimestampTooltip
                            date={reply.created_at}
                            author={reply.user}
                        >
                            <Link
                                href={permalink}
                                className="shrink-0 font-mono text-xs text-muted-foreground hover:underline"
                            >
                                <time dateTime={reply.created_at}>
                                    {formatRelativeTime(reply.created_at, now)}
                                </time>
                            </Link>
                        </TimestampTooltip>
                        {reply.status !== 'approved' && (
                            <span
                                className={cn(
                                    'shrink-0 self-center rounded-full px-1.5 font-mono text-[11px]',
                                    reply.status === 'pending'
                                        ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                        : 'bg-destructive/10 text-destructive',
                                )}
                            >
                                {reply.status}
                            </span>
                        )}
                        {isOwn && (
                            <button
                                type="button"
                                onClick={() => setIsConfirming(true)}
                                aria-label="Delete reply"
                                title="Delete reply"
                                className="-my-1 ml-auto shrink-0 self-center rounded-md p-1 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100 sm:opacity-0 sm:reply-hover:opacity-100 [@media(hover:none)]:opacity-100"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        )}
                    </div>

                    {isFlattened && (
                        <p className="flex min-w-0 items-center gap-1 font-mono text-xs text-muted-foreground">
                            <CornerDownRight className="size-3 shrink-0" />
                            <span className="truncate">
                                {isQuote ? 'quoting' : 'replying to'}{' '}
                                {isDeleted(parent)
                                    ? '[deleted]'
                                    : `@${parent.user.username}`}
                            </span>
                        </p>
                    )}

                    <FoldedCommitBody
                        segments={reply.body_segments}
                        previewSegments={reply.preview_segments}
                        className="text-[15px]"
                    />
                    <CommitImages commit={reply} />
                    {reply.link_preview && (
                        <LinkPreview
                            commitId={reply.id}
                            preview={reply.link_preview}
                        />
                    )}

                    {isConfirming ? (
                        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-destructive/8 px-3 py-1.5 text-sm">
                            <span className="mr-auto">
                                Delete this reply? You can undo for 30 seconds.
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                autoFocus
                                onClick={() => setIsConfirming(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                size="sm"
                                disabled={request.processing}
                                onClick={deleteReply}
                            >
                                Delete
                            </Button>
                        </div>
                    ) : (
                        <ThreadActions
                            thread={thread}
                            actions={
                                <>
                                    <CommitStarButton
                                        commit={reply}
                                        className="px-2 py-0.5"
                                    />
                                    <CommitPastelButton
                                        commit={reply}
                                        className="px-2 py-0.5"
                                    />
                                </>
                            }
                        />
                    )}
                </div>
            </div>

            <ThreadReplies thread={thread} depth={depth} />
        </article>
    );
}

function ReplyComposer({
    parent,
    onCancel,
    onReplied,
}: {
    parent: Commit;
    onCancel: () => void;
    onReplied: () => void;
}) {
    const { user } = useUser();
    const {
        body,
        images,
        descriptions,
        gif,
        isRestored,
        updateBody,
        updateImages,
        updateGif,
        drop,
    } = useStash(user.id, `reply-${parent.id}`);
    const attachments = useImageAttachments(images, descriptions, updateImages);
    const linkCard = useLinkCard(body);
    const updateCommitsCount = useCommitsCountUpdate();
    const request = useHttp<
        {
            body: string;
            images: File[];
            image_descriptions: string[];
            link_preview_url?: string;
            giphy_id?: string;
            giphy_url?: string;
            giphy_page_url?: string;
            giphy_title?: string;
            giphy_username?: string;
            giphy_width?: number;
            giphy_height?: number;
        },
        CreatedReply
    >({
        body: '',
        images: [],
        image_descriptions: [],
    });
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const secrets = useSecretWarning(body);
    const isReplyable =
        images.length > 0 || characterCount(body.trim()) >= minBodyLength;
    const preview = useComposerPreview({
        body,
        textareaRef,
        isSubmittable: isReplyable,
    });

    useEffect(() => {
        if (attachments.images.length > 0 && gif !== null) {
            updateGif(null);
        }
    }, [attachments.images.length, gif, updateGif]);

    const returnFocusToCommit = () => {
        const commit =
            textareaRef.current?.closest<HTMLElement>('[data-commit]');

        if (
            commit &&
            textareaRef.current?.form?.contains(document.activeElement)
        ) {
            focusCommit(commit);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (preview.handleShortcut(event)) {
            return;
        }

        if (event.key === 'Escape') {
            returnFocusToCommit();
            onCancel();
        }

        if (event.key === 'Enter' && hasModKey(event) && isReplyable) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (request.processing || !secrets.beforeSubmit()) {
            return;
        }

        request.setData(() => ({
            body,
            images: gif === null ? images : [],
            image_descriptions: gif === null ? descriptions : [],
            ...(gif !== null ? giphyFormFields(gif) : {}),
            ...(linkCard.isChosen
                ? { link_preview_url: linkCard.link ?? '' }
                : {}),
        }));

        request
            .submit(CommitReplyController.store(parent), {
                onSuccess: (reply) => {
                    attachments.clear();
                    drop();
                    linkCard.reset();
                    returnFocusToCommit();
                    router.flash(() => reply);
                    router.flushAll();
                    updateCommitsCount(1);
                    onReplied();
                },
                onHttpException: (response) => {
                    if (response.status === 404) {
                        request.setError('body', parentUnavailableMessage);

                        return;
                    }

                    toast.error(replyFailureMessage(response));
                },
                onNetworkError: () => {
                    toast.error(replyFailedMessage);
                },
            })
            .catch(() => undefined);
    };

    const { processing, errors } = request;

    return (
        <form
            onSubmit={submit}
            {...attachments.dropzone}
            className="relative mt-2 rounded-lg border bg-background p-2.5 transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
            data-test="reply-composer"
        >
            {attachments.isDraggingFiles && (
                <ImageDropOverlay className="-inset-px" />
            )}
            <label htmlFor={`reply-${parent.id}`} className="sr-only">
                Reply to @{parent.user.username}
            </label>
            <div className="grid">
                <MentionTextarea
                    ref={textareaRef}
                    id={`reply-${parent.id}`}
                    name="body"
                    rows={1}
                    autoFocus
                    value={body}
                    onValueChange={updateBody}
                    onKeyDown={onKeyDown}
                    onPaste={attachments.onPaste}
                    onFocus={isRestored ? moveCaretToEnd : undefined}
                    maxLength={maxBodyLength}
                    placeholder={`Reply to @${parent.user.username}`}
                    className={cn(
                        'col-start-1 row-start-1 block field-sizing-content max-h-[50dvh] min-h-9 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent px-1 text-base leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none md:text-[15px]',
                        { invisible: preview.isPreviewing },
                    )}
                />
                {preview.isPreviewing && (
                    <ComposerPreviewBody
                        preview={preview}
                        url={linkCard.link}
                        images={attachments.images}
                        descriptions={attachments.descriptions}
                        className="col-start-1 row-start-1 px-1"
                    />
                )}
            </div>
            {preview.isPreviewing && gif !== null && <CommitGiphy gif={gif} />}
            <InputError
                message={errors.body ?? errors.link_preview_url}
                className="px-1"
            />
            {!preview.isPreviewing && (
                <ImagePickerPreviews
                    images={attachments.images}
                    descriptions={attachments.descriptions}
                    onDescribe={attachments.describe}
                    onRemove={attachments.remove}
                />
            )}
            {!preview.isPreviewing && (
                <GiphyAttachmentPreview
                    gif={gif}
                    onRemove={() => updateGif(null)}
                />
            )}
            <SecretWarning
                body={body}
                findings={secrets.findings}
                textarea={textareaRef}
                onChange={updateBody}
                confirming={secrets.confirming}
                onConfirmingChange={secrets.setConfirming}
                onApprove={secrets.approve}
                onEdit={preview.close}
            />
            <InputError
                message={attachments.error ?? imageErrorFrom(errors)}
                className="mt-1 px-1"
            />
            <div className="mt-1.5 flex flex-wrap items-center justify-end gap-x-2 gap-y-1">
                <div className="mr-auto flex min-w-0 items-center gap-2">
                    <ImagePickerButton
                        count={images.length}
                        disabled={processing}
                        onPick={(files) => {
                            updateGif(null);
                            attachments.attach(files);
                        }}
                        className="size-8"
                    />
                    <GiphyPickerButton
                        disabled={processing}
                        hasGif={gif !== null}
                        onPick={(picked) => {
                            attachments.clear();
                            updateGif(picked);
                        }}
                        className="-ml-2 size-8"
                    />
                    <PreviewToggle preview={preview} className="-ml-2 size-8" />
                    <LinkCardPicker
                        linkCard={linkCard}
                        disabled={processing}
                        className="-ml-1"
                        labelClassName="max-sm:sr-only"
                    />
                    {isRestored && (
                        <StashLabel
                            onDrop={() => {
                                attachments.clear();
                                drop();
                                document
                                    .getElementById(`reply-${parent.id}`)
                                    ?.focus();
                            }}
                            className="px-1 text-xs"
                        />
                    )}
                </div>
                <BodyCounter body={body} className="mr-1 text-xs" />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={processing || !isReplyable}
                    className="min-w-[4.75rem]"
                    data-test="submit-reply-button"
                >
                    {processing ? 'Replying…' : 'Reply'}
                </Button>
            </div>
        </form>
    );
}
