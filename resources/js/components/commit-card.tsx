import { Link, router, usePage } from '@inertiajs/react';
import { CornerDownRight, ListOrdered, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import { BookmarkButton } from '@/components/bookmark-button';
import { CommitBody } from '@/components/commit-body';
import { CommitImages } from '@/components/commit-images';
import { CommitPoll } from '@/components/commit-poll';
import { CommitQuoteButton } from '@/components/commit-quote-button';
import { CommitStarButton } from '@/components/commit-star-button';
import {
    ThreadActions,
    ThreadReplies,
    useThread,
} from '@/components/commit-thread';
import { GitHubActivityGraph } from '@/components/github-activity';
import {
    GraphRail,
    RailAvatar,
    RailDot,
    RailSegment,
} from '@/components/graph-rail';
import { LinkPreview } from '@/components/link-preview';
import { QuotedCommit } from '@/components/quoted-commit';
import { Button } from '@/components/ui/button';
import { UserPreview } from '@/components/user-preview';
import { useNow } from '@/hooks/use-now';
import { commitMutationOptions } from '@/lib/commit-mutation-options';
import { isGitHubActivity } from '@/lib/github-activity';
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
import type {
    Commit,
    CursorPaginated,
    DeletedCommit,
    ThreadCommit,
} from '@/types';

export function CommitCard({
    commit,
    previousLane,
    isDimmed = false,
    isPreviousDimmed = false,
    isHighlighted = false,
    isGroupedWithPrevious = false,
    onAuthorHover,
    actions,
    isFocused = false,
    canDelete = true,
    initialReplies,
    threadActions,
    showImages = true,
    loadsReplies = false,
    isSeriesExpanded = false,
    endsWithDot = false,
    railNodePosition = 'top',
    hasRailTail = true,
}: {
    commit: Commit;
    previousLane?: string;
    isDimmed?: boolean;
    isPreviousDimmed?: boolean;
    isHighlighted?: boolean;
    isGroupedWithPrevious?: boolean;
    onAuthorHover?: (isHovering: boolean) => void;
    actions?: ReactNode;
    isFocused?: boolean;
    canDelete?: boolean;
    initialReplies?: CursorPaginated<ThreadCommit>;
    threadActions?: ReactNode;
    showImages?: boolean;
    loadsReplies?: boolean;
    isSeriesExpanded?: boolean;
    endsWithDot?: boolean;
    railNodePosition?: 'top' | 'center' | 'bottom' | 'author';
    hasRailTail?: boolean;
}) {
    const {
        props: { auth },
        component,
    } = usePage();
    const now = useNow();
    const nodeRef = useRef<HTMLAnchorElement>(null);
    const thread = useThread(commit, initialReplies, loadsReplies);
    const [deletion, setDeletion] = useState<
        'idle' | 'confirming' | 'deleting'
    >('idle');

    const hasVisibleReplies = thread.isOpen && !!thread.replies?.length;
    const isOwn = commit.user_id === auth.user?.id;
    const isDeletable = isOwn && canDelete;
    const hash = shortHash(commit.id);
    const permalink = CommitController.show({ user: commit.user, commit });
    const laterParts =
        commit.series_size !== null &&
        commit.series_position !== null &&
        commit.series_parts === undefined
            ? commit.series_size - commit.series_position
            : 0;

    const deleteCommit = () => {
        setDeletion('deleting');

        const prefersReducedMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        window.setTimeout(
            () =>
                router.visit(CommitController.destroy(commit), {
                    ...commitMutationOptions(component),
                    onError: () => setDeletion('idle'),
                }),
            prefersReducedMotion ? 0 : 700,
        );
    };

    return (
        <article
            className={cn(
                'grid scroll-mt-28 transition-[grid-template-rows,opacity,background-color] duration-300 ease-out outline-none focus-visible:bg-accent',
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
            <div className="grid min-h-0 grid-cols-[4rem_minmax(0,1fr)] overflow-hidden sm:grid-cols-[4.5rem_minmax(0,1fr)]">
                <GraphRail
                    lane={laneColor(commit.user.lane)}
                    previousLane={previousLane}
                    endsWithDot={endsWithDot}
                    nodePosition={railNodePosition}
                    hasTail={hasRailTail && railNodePosition !== 'bottom'}
                    hasReplyTail={
                        hasVisibleReplies && (!hasRailTail || endsWithDot)
                    }
                    isFresh={isOwn && isWithinSeconds(commit.created_at, 15)}
                    isDimmed={isDimmed}
                    isPreviousDimmed={isPreviousDimmed}
                    onHoverChange={onAuthorHover}
                    node={
                        <UserPreview
                            user={commit.user}
                            anchor={nodeRef}
                            className="flex"
                        >
                            <Link
                                ref={nodeRef}
                                href={show(commit.user)}
                                tabIndex={-1}
                                className={cn('group/node flex rounded-full', {
                                    '-translate-y-[9px]':
                                        isGroupedWithPrevious &&
                                        railNodePosition === 'top',
                                })}
                            >
                                {isGroupedWithPrevious ? (
                                    <RailDot className="group-hover/node:scale-125" />
                                ) : (
                                    <RailAvatar user={commit.user} />
                                )}
                            </Link>
                        </UserPreview>
                    }
                />

                <div
                    className={cn(
                        'min-w-0 pr-4',
                        hasVisibleReplies ? 'pt-3' : 'border-b py-3',
                    )}
                >
                    <div className="flex">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-baseline gap-1.5 text-[15px]">
                                <UserPreview
                                    user={commit.user}
                                    anchor={nodeRef}
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
                                        className="shrink-[4] truncate font-mono text-[13px] text-muted-foreground"
                                    >
                                        @{commit.user.username}
                                    </Link>
                                </UserPreview>
                                <span
                                    aria-hidden
                                    className="text-muted-foreground"
                                >
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
                                        {formatRelativeTime(
                                            commit.created_at,
                                            now,
                                        )}
                                    </time>
                                </Link>

                                {commit.series_position !== null &&
                                    commit.series_size !== null && (
                                        <SeriesPosition
                                            position={commit.series_position}
                                            size={commit.series_size}
                                        />
                                    )}

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
                                                'commit-hover:opacity-0 commit-focus-within:opacity-0 [@media(hover:none)]:opacity-0':
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
                                            className="-my-1 rounded-md p-1.5 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100 sm:absolute sm:top-1/2 sm:-right-1.5 sm:my-0 sm:-translate-y-1/2 sm:opacity-0 sm:commit-hover:opacity-100 sm:commit-focus-within:opacity-100 [@media(hover:none)]:opacity-100"
                                            data-test="delete-commit-button"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </span>
                            </div>

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
                                    replying to @{commit.parent.user.username}
                                </Link>
                            )}
                            <CommitBody
                                segments={commit.body_segments}
                                className={cn(
                                    'mt-0.5',
                                    isFocused ? 'text-[17px]' : 'text-[15px]',
                                )}
                            />
                            {isGitHubActivity(commit.body) && (
                                <GitHubActivityGraph commit={commit} />
                            )}
                            {showImages && <CommitImages commit={commit} />}
                            {commit.poll && (
                                <CommitPoll
                                    key={`${commit.poll.id}-${commit.poll.has_voted}-${commit.poll.total_votes}`}
                                    commit={commit}
                                    poll={commit.poll}
                                />
                            )}
                            {commit.quoted_commit_id !== null && (
                                <QuotedCommit
                                    commit={commit.quoted_commit ?? null}
                                />
                            )}
                            {commit.link_preview?.title && (
                                <LinkPreview preview={commit.link_preview} />
                            )}
                            {laterParts > 0 && !isSeriesExpanded && (
                                <Link
                                    href={permalink}
                                    className="mt-1.5 flex w-fit items-center gap-1.5 font-mono text-xs text-primary hover:underline"
                                    data-test="series-continues"
                                >
                                    <ListOrdered className="size-3.5" />
                                    {laterParts} more{' '}
                                    {laterParts === 1 ? 'commit' : 'commits'} in
                                    this series
                                </Link>
                            )}
                            {isFocused && (
                                <p className="mt-2 font-mono text-[13px] text-muted-foreground">
                                    <time dateTime={commit.created_at}>
                                        {formatFullDateTime(commit.created_at)}
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

                            {commit.replies_count === undefined ? (
                                <div className="mt-1.5 -ml-1.5 flex items-center gap-0.5">
                                    <CommitStarButton commit={commit} />
                                    <CommitQuoteButton
                                        commit={commit}
                                        keyShortcut="q"
                                        className="px-1.5 py-1"
                                    />
                                </div>
                            ) : (
                                <ThreadActions
                                    thread={thread}
                                    hasKeyboardShortcuts
                                    actions={
                                        <>
                                            <CommitStarButton
                                                commit={commit}
                                                className="px-2 py-0.5"
                                            />
                                            <CommitQuoteButton
                                                commit={commit}
                                                keyShortcut="q"
                                            />
                                            {threadActions}
                                        </>
                                    }
                                />
                            )}

                            {actions}

                            {commit.replies_count === undefined &&
                                commit.is_saved !== undefined && (
                                    <div className="mt-1 -ml-1.5">
                                        <BookmarkButton commit={commit} />
                                    </div>
                                )}

                            {deletion === 'deleting' && (
                                <p
                                    className="mt-3 flex min-h-12 items-center rounded-md bg-destructive/8 px-3 py-2 font-mono text-sm text-muted-foreground"
                                    role="status"
                                >
                                    rm {hash} → /dev/null
                                </p>
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
                                    className="mt-3 flex min-h-12 flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-destructive/8 px-3 py-2 text-sm"
                                >
                                    <span className="mr-auto">
                                        Delete this commit? You can undo for 30
                                        seconds.
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
                {commit.replies_count !== undefined && (
                    <>
                        <RailSegment
                            lane={
                                hasRailTail && !endsWithDot
                                    ? laneColor(commit.user.lane)
                                    : undefined
                            }
                            isDimmed={isDimmed}
                        />
                        <div
                            className={cn('min-w-0 pr-4', {
                                'border-b pb-3': hasVisibleReplies,
                            })}
                        >
                            <ThreadReplies thread={thread} depth={0} />
                        </div>
                    </>
                )}
            </div>
        </article>
    );
}

export function SeriesPosition({
    position,
    size,
}: {
    position: number;
    size: number;
}) {
    return (
        <span
            className="shrink-0 self-center rounded-full bg-primary-soft px-2 py-0.5 font-mono text-xs text-primary"
            title={`Commit ${position} of ${size} in a series`}
            data-test="series-position"
        >
            {position}/{size}
        </span>
    );
}

export function CommitTombstone({
    commit,
    previousLane,
}: {
    commit?: DeletedCommit;
    previousLane?: string;
}) {
    return (
        <article className="flex" data-test="commit-tombstone">
            <GraphRail
                lane="var(--rail)"
                previousLane={previousLane}
                node={
                    <span className="size-12 rounded-full border-2 border-dashed border-rail bg-card" />
                }
            />

            <div className="flex min-w-0 flex-1 items-center gap-3 border-b py-3 pr-4">
                <p className="flex items-baseline gap-1.5 text-[15px] text-muted-foreground">
                    <span className="font-mono text-[13px]">[deleted]</span>
                    This commit was deleted.
                </p>
                {commit?.series_position !== undefined &&
                    commit.series_size !== undefined && (
                        <span className="ml-auto">
                            <SeriesPosition
                                position={commit.series_position}
                                size={commit.series_size}
                            />
                        </span>
                    )}
            </div>
        </article>
    );
}
