import { Form, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    CornerDownRight,
    MessageSquareReply,
    Trash2,
} from 'lucide-react';
import type { KeyboardEvent, ReactNode } from 'react';
import { useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import CommitReplyController from '@/actions/App/Http/Controllers/CommitReplyController';
import { BookmarkButton } from '@/components/bookmark-button';
import { CommitBody } from '@/components/commit-body';
import {
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import { CommitImages } from '@/components/commit-images';
import { CopyLinkButton } from '@/components/copy-link-button';
import InputError from '@/components/input-error';
import { LinkPreview } from '@/components/link-preview';
import { MentionTextarea } from '@/components/mention-textarea';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { UserPreview } from '@/components/user-preview';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { focusCommit } from '@/hooks/use-keyboard-shortcuts';
import { useNow } from '@/hooks/use-now';
import { moveCaretToEnd, useHasStash, useStash } from '@/hooks/use-stash';
import { cn, formatRelativeTime, isDeleted } from '@/lib/utils';
import { show } from '@/routes/user';
import type { Commit, CursorPaginated, ThreadCommit } from '@/types';

const maxIndentDepth = 3;
const maxBodyLength = 280;

type Thread = {
    commit: ThreadCommit;
    count: number;
    replies: ThreadCommit[] | null;
    hasMore: boolean;
    isOpen: boolean;
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
    commit: ThreadCommit,
    cursor: string | null,
): Promise<CursorPaginated<ThreadCommit>> {
    const response = await fetch(
        CommitReplyController.index(commit, { query: { cursor } }).url,
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
): Promise<CursorPaginated<ThreadCommit>> {
    const page = await fetchReplies(commit, null);
    let data = page.data;
    let cursor = page.next_cursor;

    while (cursor !== null && data.length < minimum) {
        const next = await fetchReplies(commit, cursor);
        data = [...data, ...next.data];
        cursor = next.next_cursor;
    }

    return { ...page, data, next_cursor: cursor };
}

function useThread(
    commit: ThreadCommit,
    initialReplies?: CursorPaginated<ThreadCommit>,
): Thread {
    const [replies, setReplies] = useState<ThreadCommit[] | null>(
        initialReplies?.data ?? null,
    );
    const [nextCursor, setNextCursor] = useState<string | null>(
        initialReplies?.next_cursor ?? null,
    );
    const [count, setCount] = useState(commit.replies_count ?? 0);
    const [isOpen, setIsOpen] = useState(initialReplies !== undefined);
    const [isLoading, setIsLoading] = useState(false);
    const [isReplying, setIsReplying] = useState(false);

    const applyPage = (loaded: ThreadCommit[], cursor: string | null) => {
        setReplies(loaded);
        setNextCursor(cursor);

        if (cursor === null) {
            setCount(loaded.length);
        }
    };

    const reload = () => {
        const minimum =
            replies === null
                ? 0
                : nextCursor === null
                  ? Infinity
                  : replies.length;

        setIsLoading(true);

        fetchRepliesUntil(commit, minimum)
            .then((page) => applyPage(page.data, page.next_cursor))
            .catch(() => undefined)
            .finally(() => setIsLoading(false));
    };

    return {
        commit,
        count,
        replies,
        hasMore: replies !== null && nextCursor !== null,
        isOpen,
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

            fetchReplies(commit, nextCursor)
                .then((page) =>
                    applyPage([...replies, ...page.data], page.next_cursor),
                )
                .catch(() => undefined)
                .finally(() => setIsLoading(false));
        },
        startReply: () => setIsReplying(true),
        cancelReply: () => setIsReplying(false),
        onReplied: () => {
            setIsReplying(false);
            setIsOpen(true);
            setCount((current) => current + 1);
            reload();
        },
        onDeleted: () => {
            setCount((current) => Math.max(current - 1, 0));
            reload();
        },
    };
}

export function CommitThread({
    commit,
    initialReplies,
    actions,
}: {
    commit: Commit;
    initialReplies?: CursorPaginated<ThreadCommit>;
    actions?: ReactNode;
}) {
    const thread = useThread(commit, initialReplies);

    return (
        <>
            <ThreadActions
                thread={thread}
                actions={actions}
                hasKeyboardShortcuts
            />
            <ThreadReplies thread={thread} depth={0} />
        </>
    );
}

function ThreadActions({
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
    const hasStash = useHasStash(auth.user.id, `reply-${commit.id}`);

    return (
        <>
            <div className="mt-1 -ml-2 flex flex-wrap items-center justify-between gap-0.5 text-[13px]">
                <div className="flex flex-wrap items-center gap-0.5">
                    {actions}

                    {!isDeleted(commit) && (
                        <button
                            type="button"
                            onClick={
                                thread.isReplying
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

                    {thread.count > 0 && (
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

                <div className="flex items-center gap-0.5">
                    {!isDeleted(commit) && commit.is_saved !== undefined && (
                        <BookmarkButton commit={commit} />
                    )}
                    {!isDeleted(commit) && (
                        <CopyLinkButton
                            commit={commit}
                            keyShortcut={hasKeyboardShortcuts ? 'y' : undefined}
                        />
                    )}
                </div>
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

function ThreadReplies({ thread, depth }: { thread: Thread; depth: number }) {
    if (!thread.isOpen) {
        return null;
    }

    return (
        <div
            className={cn('mt-1', {
                'ml-3 border-l border-rail pl-2 sm:ml-3.5 sm:pl-3':
                    depth < maxIndentDepth && depth > 0,
            })}
            data-test="replies"
        >
            {thread.replies === null ? (
                <p className="py-2 text-sm text-muted-foreground" role="status">
                    Loading replies…
                </p>
            ) : (
                <>
                    {thread.replies.map((reply) => (
                        <ReplyItem
                            key={reply.id}
                            reply={reply}
                            parent={thread.commit}
                            depth={depth + 1}
                            onDeleted={thread.onDeleted}
                        />
                    ))}
                    {thread.hasMore && (
                        <MoreRepliesButton
                            thread={thread}
                            isFlattened={depth + 1 > maxIndentDepth}
                        />
                    )}
                </>
            )}
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
    const remaining = thread.count - (thread.replies?.length ?? 0);

    return (
        <button
            type="button"
            onClick={thread.loadMore}
            aria-disabled={thread.isLoading}
            className="mt-1.5 -ml-2 flex max-w-full items-center gap-1 rounded-md px-2 py-0.5 text-[13px] text-muted-foreground transition-colors [overflow-anchor:none] hover:bg-accent hover:text-foreground aria-disabled:pointer-events-none"
            data-test="more-replies-button"
        >
            <ChevronDown className="size-4 shrink-0" />
            {thread.isLoading ? (
                <span role="status">Loading replies…</span>
            ) : (
                <span className="truncate">
                    {remaining > 0
                        ? `Show ${remaining} more ${remaining === 1 ? 'reply' : 'replies'}`
                        : 'Show more replies'}
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
    onDeleted,
}: {
    reply: ThreadCommit;
    parent: ThreadCommit;
    depth: number;
    onDeleted: () => void;
}) {
    const thread = useThread(reply);

    if (isDeleted(reply)) {
        return (
            <article className="pt-2.5" data-test="reply-tombstone">
                <div className="flex gap-2 sm:gap-2.5">
                    <span
                        aria-hidden
                        className="size-6 shrink-0 rounded-full border-2 border-dashed border-rail sm:size-7"
                    />
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
            thread={thread}
            onDeleted={onDeleted}
        />
    );
}

function CommitReply({
    reply,
    parent,
    depth,
    thread,
    onDeleted,
}: {
    reply: Commit;
    parent: ThreadCommit;
    depth: number;
    thread: Thread;
    onDeleted: () => void;
}) {
    const { auth } = usePage().props;
    const now = useNow();
    const [isConfirming, setIsConfirming] = useState(false);

    const isOwn = reply.user_id === auth.user.id;
    const isFlattened = depth > maxIndentDepth;

    const deleteReply = () =>
        router.visit(CommitController.destroy(reply), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onDeleted,
        });

    return (
        <article className="group/reply pt-2.5" data-test="reply">
            <div className="flex gap-2 sm:gap-2.5">
                <UserPreview user={reply.user} className="shrink-0 self-start">
                    <Link
                        href={show(reply.user)}
                        className="block rounded-full"
                    >
                        <UserAvatar
                            user={reply.user}
                            className="size-6 sm:size-7"
                        />
                    </Link>
                </UserPreview>

                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-1.5 text-sm">
                        <UserPreview
                            user={reply.user}
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
                        <span aria-hidden className="text-muted-foreground">
                            ·
                        </span>
                        <Link
                            href={CommitController.show({
                                user: reply.user,
                                commit: reply,
                            })}
                            className="shrink-0 font-mono text-xs text-muted-foreground hover:underline"
                        >
                            <time
                                dateTime={reply.created_at}
                                title={new Date(
                                    reply.created_at,
                                ).toLocaleString()}
                            >
                                {formatRelativeTime(reply.created_at, now)}
                            </time>
                        </Link>
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
                                className="-my-1 ml-auto shrink-0 self-center rounded-md p-1 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100 sm:opacity-0 sm:group-hover/reply:opacity-100 [@media(hover:none)]:opacity-100"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        )}
                    </div>

                    {isFlattened && (
                        <p className="flex min-w-0 items-center gap-1 font-mono text-xs text-muted-foreground">
                            <CornerDownRight className="size-3 shrink-0" />
                            <span className="truncate">
                                {isDeleted(parent)
                                    ? 'replying to [deleted]'
                                    : `replying to @${parent.user.username}`}
                            </span>
                        </p>
                    )}

                    <CommitBody
                        segments={reply.body_segments}
                        className="text-[15px]"
                    />
                    {reply.images && <CommitImages images={reply.images} />}
                    {reply.link_preview?.title && (
                        <LinkPreview preview={reply.link_preview} />
                    )}

                    {isConfirming ? (
                        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-destructive/8 px-3 py-1.5 text-sm">
                            <span className="mr-auto">Delete this reply?</span>
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
                                onClick={deleteReply}
                            >
                                Delete
                            </Button>
                        </div>
                    ) : (
                        <ThreadActions thread={thread} />
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
    const { auth } = usePage().props;
    const { body, isRestored, updateBody, drop } = useStash(
        auth.user.id,
        `reply-${parent.id}`,
    );
    const attachments = useImageAttachments();
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const remaining = maxBodyLength - body.length;
    const isReplyable = body.trim().length >= 3;

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
        if (event.key === 'Escape') {
            returnFocusToCommit();
            onCancel();
        }

        if (
            event.key === 'Enter' &&
            (event.metaKey || event.ctrlKey) &&
            isReplyable
        ) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <Form
            {...CommitController.store.form()}
            errorBag="reply"
            transform={(data) =>
                attachments.images.length > 0
                    ? { ...data, images: attachments.images }
                    : data
            }
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={(page) => {
                if (page.flash.committed) {
                    drop();
                    attachments.clear();
                    returnFocusToCommit();
                    onReplied();
                }
            }}
            className="mt-2 rounded-lg border bg-background p-2.5"
            data-test="reply-composer"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="parent_id" value={parent.id} />
                    <label htmlFor={`reply-${parent.id}`} className="sr-only">
                        Reply to @{parent.user.username}
                    </label>
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
                        onDragOver={attachments.onDragOver}
                        onDrop={attachments.onDrop}
                        onFocus={isRestored ? moveCaretToEnd : undefined}
                        maxLength={maxBodyLength}
                        placeholder={`Reply to @${parent.user.username}`}
                        className="block field-sizing-content min-h-9 w-full resize-none bg-transparent px-1 text-[15px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none"
                    />
                    <InputError
                        message={errors.body ?? errors.parent_id}
                        className="px-1"
                    />
                    <ImagePickerPreviews
                        images={attachments.images}
                        onRemove={attachments.remove}
                    />
                    <InputError
                        message={attachments.error ?? imageErrorFrom(errors)}
                        className="mt-1 px-1"
                    />
                    <div className="mt-1.5 flex items-center justify-end gap-2">
                        <div className="mr-auto flex items-center gap-2">
                            <ImagePickerButton
                                count={attachments.images.length}
                                disabled={processing}
                                onPick={attachments.attach}
                                className="size-8"
                            />
                            {isRestored && (
                                <StashLabel
                                    onDrop={() => {
                                        drop();
                                        document
                                            .getElementById(
                                                `reply-${parent.id}`,
                                            )
                                            ?.focus();
                                    }}
                                    className="px-1 text-xs"
                                />
                            )}
                        </div>
                        <span
                            className={cn(
                                'mr-1 font-mono text-xs text-muted-foreground',
                                {
                                    'text-amber-700 dark:text-amber-400':
                                        remaining <= 20,
                                },
                            )}
                        >
                            {remaining}
                        </span>
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
                            data-test="submit-reply-button"
                        >
                            {processing ? 'Replying…' : 'Reply'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
