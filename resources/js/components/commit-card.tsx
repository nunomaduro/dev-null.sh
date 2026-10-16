import { Link, router, usePage } from '@inertiajs/react';
import { CornerDownRight, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import { BookmarkButton } from '@/components/bookmark-button';
import { CommitBody } from '@/components/commit-body';
import { CommitImages } from '@/components/commit-images';
import { CommitStarButton } from '@/components/commit-star-button';
import { CommitThread } from '@/components/commit-thread';
import { GraphRail } from '@/components/graph-rail';
import { LinkPreview } from '@/components/link-preview';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { UserPreview } from '@/components/user-preview';
import { useNow } from '@/hooks/use-now';
import {
    cn,
    formatFullDateTime,
    formatRelativeTime,
    isDeleted,
    isWithinSeconds,
    laneColor,
    shortHash,
} from '@/lib/utils';
import { show } from '@/routes/user';
import type { Commit, CursorPaginated, ThreadCommit } from '@/types';

export function CommitCard({
    commit,
    previousLane,
    isDimmed = false,
    isPreviousDimmed = false,
    isHighlighted = false,
    onAuthorHover,
    actions,
    isFocused = false,
    canDelete = true,
    initialReplies,
    threadActions,
}: {
    commit: Commit;
    previousLane?: string;
    isDimmed?: boolean;
    isPreviousDimmed?: boolean;
    isHighlighted?: boolean;
    onAuthorHover?: (isHovering: boolean) => void;
    actions?: ReactNode;
    isFocused?: boolean;
    canDelete?: boolean;
    initialReplies?: CursorPaginated<ThreadCommit>;
    threadActions?: ReactNode;
}) {
    const { auth } = usePage().props;
    const now = useNow();
    const [deletion, setDeletion] = useState<
        'idle' | 'confirming' | 'deleting'
    >('idle');

    const isOwn = commit.user_id === auth.user.id;
    const isDeletable = isOwn && canDelete;
    const hash = shortHash(commit.id);
    const permalink = CommitController.show({ user: commit.user, commit });

    const deleteCommit = () => {
        setDeletion('deleting');

        const prefersReducedMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        window.setTimeout(
            () =>
                router.visit(CommitController.destroy(commit), {
                    preserveScroll: true,
                    onError: () => setDeletion('idle'),
                }),
            prefersReducedMotion ? 0 : 700,
        );
    };

    return (
        <article
            className={cn(
                'group grid scroll-mt-28 transition-[grid-template-rows,opacity,background-color] duration-300 ease-out outline-none focus-visible:bg-accent',
                { 'bg-primary-soft/60': isHighlighted },
                deletion === 'deleting'
                    ? 'grid-rows-[0fr] opacity-0 delay-400'
                    : 'grid-rows-[1fr]',
            )}
            tabIndex={-1}
            data-commit={commit.id}
            data-permalink={permalink.url}
            data-test="commit"
        >
            <div className="flex min-h-0 overflow-hidden">
                <GraphRail
                    lane={laneColor(commit.user.lane)}
                    previousLane={previousLane}
                    isFresh={isOwn && isWithinSeconds(commit.created_at, 15)}
                    isDimmed={isDimmed}
                    isPreviousDimmed={isPreviousDimmed}
                    onHoverChange={onAuthorHover}
                />

                <div className="flex min-w-0 flex-1 gap-3 border-b py-3 pr-4">
                    <UserPreview
                        user={commit.user}
                        className="shrink-0 self-start"
                        onHoverChange={onAuthorHover}
                    >
                        <Link
                            href={show(commit.user)}
                            className="shrink-0 rounded-full"
                        >
                            <UserAvatar
                                user={commit.user}
                                className="size-10"
                            />
                        </Link>
                    </UserPreview>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-baseline gap-1.5 text-[15px]">
                            <UserPreview
                                user={commit.user}
                                className="flex min-w-0 items-baseline gap-1.5"
                                onHoverChange={onAuthorHover}
                            >
                                <Link
                                    href={show(commit.user)}
                                    className="truncate font-semibold hover:underline"
                                >
                                    {commit.user.name}
                                </Link>
                                <Link
                                    href={show(commit.user)}
                                    tabIndex={-1}
                                    className="truncate font-mono text-[13px] text-muted-foreground"
                                >
                                    @{commit.user.username}
                                </Link>
                            </UserPreview>
                            <span aria-hidden className="text-muted-foreground">
                                ·
                            </span>
                            <Link
                                href={permalink}
                                className="shrink-0 font-mono text-[13px] text-muted-foreground hover:underline"
                                data-test="commit-permalink"
                            >
                                <time
                                    dateTime={commit.created_at}
                                    title={new Date(
                                        commit.created_at,
                                    ).toLocaleString()}
                                >
                                    {formatRelativeTime(commit.created_at, now)}
                                </time>
                            </Link>

                            {commit.status !== 'approved' && (
                                <span
                                    className={cn(
                                        'shrink-0 self-center rounded-full px-2 py-0.5 font-mono text-xs',
                                        commit.status === 'pending'
                                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                            : 'bg-destructive/10 text-destructive',
                                    )}
                                    title={
                                        commit.status === 'pending'
                                            ? 'Only you can see this commit until it is approved.'
                                            : 'This commit was rejected. Only you can see it.'
                                    }
                                    data-test="commit-status"
                                >
                                    {commit.status}
                                </span>
                            )}

                            <span className="relative ml-auto flex shrink-0 items-center self-center">
                                <Link
                                    href={permalink}
                                    tabIndex={-1}
                                    className={cn(
                                        'hidden font-mono text-[13px] text-muted-foreground transition-opacity hover:text-foreground sm:inline',
                                        {
                                            'group-focus-within:opacity-0 group-hover:opacity-0 [@media(hover:none)]:opacity-0':
                                                isDeletable,
                                            'sm:hidden': isFocused,
                                        },
                                    )}
                                    title={`Commit ${commit.id}`}
                                >
                                    {hash}
                                </Link>

                                {isDeletable && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDeletion('confirming')
                                        }
                                        aria-label="Delete commit"
                                        title="Delete commit"
                                        className="-my-1 rounded-md p-1.5 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100 sm:absolute sm:top-1/2 sm:-right-1.5 sm:my-0 sm:-translate-y-1/2 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100 [@media(hover:none)]:opacity-100"
                                        data-test="delete-commit-button"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                )}
                            </span>
                        </div>

                        {deletion === 'deleting' ? (
                            <p
                                className="mt-0.5 font-mono text-sm text-muted-foreground"
                                role="status"
                            >
                                rm {hash} → /dev/null
                            </p>
                        ) : (
                            <>
                                {commit.parent && isDeleted(commit.parent) && (
                                    <p className="mt-0.5 flex w-fit items-center gap-1 font-mono text-xs text-muted-foreground">
                                        <CornerDownRight className="size-3" />
                                        replying to [deleted]
                                    </p>
                                )}
                                {commit.parent && !isDeleted(commit.parent) && (
                                    <Link
                                        href={CommitController.show({
                                            user: commit.parent.user,
                                            commit: commit.parent,
                                        })}
                                        className="mt-0.5 flex w-fit items-center gap-1 font-mono text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        <CornerDownRight className="size-3" />
                                        replying to @
                                        {commit.parent.user.username}
                                    </Link>
                                )}
                                <CommitBody
                                    segments={commit.body_segments}
                                    className={cn(
                                        'mt-0.5',
                                        isFocused
                                            ? 'text-[17px]'
                                            : 'text-[15px]',
                                    )}
                                />
                                {commit.images && (
                                    <CommitImages images={commit.images} />
                                )}
                                {commit.link_preview?.title && (
                                    <LinkPreview
                                        preview={commit.link_preview}
                                    />
                                )}
                                {isFocused && (
                                    <p className="mt-2 font-mono text-[13px] text-muted-foreground">
                                        <time dateTime={commit.created_at}>
                                            {formatFullDateTime(
                                                commit.created_at,
                                            )}
                                        </time>
                                        <span aria-hidden> · </span>
                                        <span
                                            title={`Commit ${commit.id}`}
                                            className="whitespace-nowrap"
                                        >
                                            commit {hash}
                                        </span>
                                    </p>
                                )}
                            </>
                        )}

                        {deletion !== 'deleting' &&
                            (commit.replies_count === undefined ? (
                                <CommitStarButton
                                    key={`${commit.starred}-${commit.stars_count}`}
                                    commit={commit}
                                    className="mt-1.5 -ml-1.5"
                                />
                            ) : (
                                <CommitThread
                                    commit={commit}
                                    initialReplies={initialReplies}
                                    actions={
                                        <>
                                            <CommitStarButton
                                                key={`${commit.starred}-${commit.stars_count}`}
                                                commit={commit}
                                                className="px-2 py-0.5"
                                            />
                                            {threadActions}
                                        </>
                                    }
                                />
                            ))}

                        {actions}

                        {commit.replies_count === undefined &&
                            commit.is_saved !== undefined && (
                                <div className="mt-1 -ml-1.5">
                                    <BookmarkButton commit={commit} />
                                </div>
                            )}

                        {deletion === 'confirming' && (
                            <div
                                role="group"
                                aria-label="Confirm delete"
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        setDeletion('idle');
                                    }
                                }}
                                className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-destructive/8 px-3 py-2 text-sm"
                            >
                                <span className="mr-auto">
                                    Delete this commit? There's no undo.
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    autoFocus
                                    onClick={() => setDeletion('idle')}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={deleteCommit}
                                    data-test="confirm-delete-commit-button"
                                >
                                    Delete
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </article>
    );
}

export function CommitTombstone({ previousLane }: { previousLane?: string }) {
    return (
        <article className="flex" data-test="commit-tombstone">
            <GraphRail
                lane="var(--rail)"
                previousLane={previousLane}
                isFilled={false}
            />

            <div className="flex min-w-0 flex-1 items-center gap-3 border-b py-3 pr-4">
                <span
                    aria-hidden
                    className="size-10 shrink-0 rounded-full border-2 border-dashed border-rail"
                />
                <p className="flex items-baseline gap-1.5 text-[15px] text-muted-foreground">
                    <span className="font-mono text-[13px]">[deleted]</span>
                    This commit was deleted.
                </p>
            </div>
        </article>
    );
}
