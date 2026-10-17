import { InfiniteScroll, router, usePage } from '@inertiajs/react';
import { Pin } from 'lucide-react';
import type { ReactNode } from 'react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { CommitCard } from '@/components/commit-card';
import { GraphRail, RailSegment } from '@/components/graph-rail';
import { useNow } from '@/hooks/use-now';
import {
    restoreFeedCommit,
    updateQuotedCommits,
} from '@/lib/commit-mutation-options';
import { formatDayLabel, laneColor, localDayKey } from '@/lib/utils';
import type { Commit, CursorPaginated } from '@/types';

const interludeAfter = 5;

export function CommitFeed({
    commits,
    empty,
    end,
    firstLane,
    highlightedPeriod = null,
    firstSeenId = null,
    actions,
    threadActions,
    pinnedCommit = null,
    latestCommits = [],
    isRanked = false,
    isFolded = true,
    interlude,
}: {
    commits: CursorPaginated<Commit>;
    empty: ReactNode;
    end: string;
    firstLane?: string;
    highlightedPeriod?: string | null;
    firstSeenId?: string | null;
    actions?: (commit: Commit) => ReactNode;
    threadActions?: (commit: Commit) => ReactNode;
    pinnedCommit?: Commit | null;
    latestCommits?: Commit[];
    isRanked?: boolean;
    isFolded?: boolean;
    interlude?: (lane: string, isDimmed: boolean) => ReactNode;
}) {
    const now = useNow();
    const page = usePage();
    const isModeration = page.component === 'moderation/show';
    const isSaved = page.component === 'bookmark/index';
    const isLog = page.component === 'log/show';
    const deletedLatest = useRef(new Set<string>());
    const profileUserId =
        page.component === 'user/show'
            ? (page.props.user as { id: string }).id
            : null;
    const isProfileReplies =
        profileUserId !== null && page.props.tab === 'replies';
    const [highlightedAuthor, setHighlightedAuthor] = useState<string | null>(
        null,
    );
    const [highlightedCommit, setHighlightedCommit] = useState<string | null>(
        null,
    );
    const unhighlightTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(unhighlightTimer.current), []);

    const highlightCommit = (commitId: string, isHovering: boolean) => {
        clearTimeout(unhighlightTimer.current);

        if (isHovering) {
            setHighlightedCommit(commitId);

            return;
        }

        // Crossing a day marker between two commits shouldn't flash the whole feed back on.
        unhighlightTimer.current = setTimeout(
            () => setHighlightedCommit(null),
            150,
        );
    };

    useEffect(
        () =>
            router.on('flash', (event) => {
                if (isModeration) {
                    return;
                }

                const { deleted, restored } = event.detail.flash;
                if (deleted) {
                    router.replaceProp('commits.data', (rows: Commit[]) =>
                        updateQuotedCommits(
                            rows.filter((commit) => commit.id !== deleted.id),
                            deleted,
                        ),
                    );
                }
                if (deleted && isLog) {
                    router.replaceProp('latestCommits', (rows: Commit[]) => {
                        if (rows.some((commit) => commit.id === deleted.id)) {
                            deletedLatest.current.add(deleted.id);
                        }
                        return updateQuotedCommits(
                            rows.filter((commit) => commit.id !== deleted.id),
                            deleted,
                        );
                    });
                }
                if (restored) {
                    const wasLatest = deletedLatest.current.delete(restored.id);
                    const belongsInFeed =
                        (isSaved
                            ? restored.is_saved === true
                            : isProfileReplies
                              ? restored.parent_id !== null
                              : restored.parent_id === null) &&
                        (profileUserId === null ||
                            (restored.user_id === profileUserId &&
                                !restored.is_pinned));

                    router.replaceProp('commits.data', (rows: Commit[]) =>
                        belongsInFeed && !(isRanked && wasLatest)
                            ? restoreFeedCommit(
                                  rows,
                                  restored,
                                  isSaved
                                      ? 'saved'
                                      : isRanked
                                        ? 'ranked'
                                        : 'latest',
                              )
                            : updateQuotedCommits(rows, restored),
                    );
                    if (isLog) {
                        router.replaceProp('latestCommits', (rows: Commit[]) =>
                            belongsInFeed && wasLatest
                                ? restoreFeedCommit(rows, restored, 'latest')
                                : updateQuotedCommits(rows, restored),
                        );
                    }
                }
            }),
        [
            profileUserId,
            isModeration,
            isProfileReplies,
            isSaved,
            isRanked,
            isLog,
        ],
    );

    // A ranked commit can move to a later page while the feed scrolls, so each one is only listed once.
    const seenIds = new Set(latestCommits.map((commit) => commit.id));
    const listed = commits.data.filter((commit) => {
        if (seenIds.has(commit.id)) {
            return false;
        }

        seenIds.add(commit.id);

        return true;
    });

    if (
        listed.length === 0 &&
        latestCommits.length === 0 &&
        pinnedCommit === null
    ) {
        return <div className="px-6 py-16 text-center sm:px-12">{empty}</div>;
    }

    const isDimmed = (commit: Commit) =>
        highlightedAuthor !== null
            ? commit.user_id !== highlightedAuthor
            : highlightedCommit !== null && commit.id !== highlightedCommit;

    const lastCommit = listed.at(-1) ?? latestCommits.at(-1) ?? pinnedCommit;
    const pinnedLane = pinnedCommit
        ? laneColor(pinnedCommit.user.lane)
        : firstLane;
    const lastLatest = latestCommits.at(-1);
    const listLane = lastLatest ? laneColor(lastLatest.user.lane) : pinnedLane;
    const interludeIndex = Math.min(interludeAfter, listed.length) - 1;

    const card = (
        commit: Commit,
        previous: Commit | undefined,
        previousLane: string | undefined,
        isGroupedWithPrevious: boolean,
        isGroupedWithNext: boolean,
    ) => (
        <CommitCard
            commit={commit}
            endsWithDot={!isGroupedWithPrevious && !isGroupedWithNext}
            railNodePosition={isGroupedWithPrevious ? 'author' : 'top'}
            hasRailTail={!isGroupedWithPrevious || isGroupedWithNext}
            previousLane={isGroupedWithPrevious ? previousLane : undefined}
            isDimmed={isDimmed(commit)}
            isGroupedWithPrevious={isGroupedWithPrevious}
            isPreviousDimmed={
                previous
                    ? isDimmed(previous)
                    : highlightedAuthor !== null || highlightedCommit !== null
            }
            isHighlighted={
                highlightedPeriod !== null &&
                commit.created_at.startsWith(highlightedPeriod)
            }
            actions={actions?.(commit)}
            threadActions={threadActions?.(commit)}
            isFolded={isFolded}
            onAuthorHover={(isHovering) =>
                setHighlightedAuthor(isHovering ? commit.user_id : null)
            }
            onHover={(isHovering) => highlightCommit(commit.id, isHovering)}
        />
    );

    return (
        <>
            {pinnedCommit && (
                <section aria-label="Pinned commit">
                    <div className="flex">
                        <RailSegment />
                        <p className="flex items-center gap-1.5 pt-3 pb-1 font-mono text-xs text-muted-foreground">
                            <Pin aria-hidden className="size-3" />
                            Pinned
                        </p>
                    </div>
                    <CommitCard
                        commit={pinnedCommit}
                        endsWithDot
                        isDimmed={isDimmed(pinnedCommit)}
                        onHover={(isHovering) =>
                            highlightCommit(pinnedCommit.id, isHovering)
                        }
                        isHighlighted={
                            highlightedPeriod !== null &&
                            pinnedCommit.created_at.startsWith(
                                highlightedPeriod,
                            )
                        }
                        threadActions={threadActions?.(pinnedCommit)}
                    />
                </section>
            )}
            {latestCommits.length > 0 && (
                <section aria-label="Latest commits">
                    <DayMarker label="Just now" />
                    {latestCommits.map((commit, index) => (
                        <Fragment key={commit.id}>
                            {card(
                                commit,
                                latestCommits[index - 1],
                                index > 0
                                    ? laneColor(
                                          latestCommits[index - 1].user.lane,
                                      )
                                    : pinnedLane,
                                latestCommits[index - 1]?.user_id ===
                                    commit.user_id,
                                latestCommits[index + 1]?.user_id ===
                                    commit.user_id,
                            )}
                        </Fragment>
                    ))}
                    {listed.length > 0 && <DayMarker label="Top commits" />}
                </section>
            )}
            <InfiniteScroll
                data="commits"
                onlyNext
                preserveUrl
                loading={
                    <div role="status" aria-label="Loading more commits">
                        <CommitSkeleton />
                        <CommitSkeleton />
                    </div>
                }
            >
                {listed.map((commit, index) => {
                    const previous = listed[index - 1];
                    const previousLane = previous
                        ? laneColor(previous.user.lane)
                        : listLane;
                    const startsDay =
                        !isRanked &&
                        (!previous ||
                            localDayKey(previous.created_at) !==
                                localDayKey(commit.created_at));
                    const isGroupedWithPrevious =
                        previous?.user_id === commit.user_id &&
                        !startsDay &&
                        (isRanked || commit.id !== firstSeenId) &&
                        !(interlude && index - 1 === interludeIndex);
                    const next = listed[index + 1];
                    const isGroupedWithNext =
                        next?.user_id === commit.user_id &&
                        (isRanked ||
                            (localDayKey(next.created_at) ===
                                localDayKey(commit.created_at) &&
                                next.id !== firstSeenId)) &&
                        !(interlude && index === interludeIndex);

                    return (
                        <Fragment key={commit.id}>
                            {!isRanked && commit.id === firstSeenId && (
                                <LastVisitMarker />
                            )}
                            {startsDay && (
                                <DayMarker
                                    label={formatDayLabel(
                                        commit.created_at,
                                        now,
                                    )}
                                />
                            )}
                            {card(
                                commit,
                                previous,
                                previousLane,
                                isGroupedWithPrevious,
                                isGroupedWithNext,
                            )}
                            {index === interludeIndex &&
                                interlude?.(
                                    laneColor(commit.user.lane),
                                    isDimmed(commit),
                                )}
                        </Fragment>
                    );
                })}
            </InfiniteScroll>

            {commits.next_cursor === null && lastCommit && (
                <div className="flex">
                    <GraphRail
                        lane={laneColor(lastCommit.user.lane)}
                        variant="root"
                        hasTail={false}
                    />
                    <p className="pt-[1.3rem] pr-4 pb-10 text-sm text-muted-foreground">
                        {end}
                    </p>
                </div>
            )}
        </>
    );
}

function DayMarker({ label }: { label: string }) {
    return (
        <div className="flex">
            <RailSegment />
            <div className="flex flex-1 items-center gap-3 border-b py-2 pr-4">
                <h3 className="font-mono text-xs text-muted-foreground">
                    {label}
                </h3>
            </div>
        </div>
    );
}

function LastVisitMarker() {
    return (
        <div
            role="separator"
            aria-label="Commits above are new since your last visit"
            className="flex"
        >
            <RailSegment />
            <div className="flex flex-1 items-center gap-3 border-b py-2 pr-4">
                <span className="font-mono text-xs text-muted-foreground">
                    HEAD@{'{last visit}'}
                </span>
                <span className="flex-1 border-t border-dashed" />
            </div>
        </div>
    );
}

export function CommitSkeleton() {
    return (
        <div aria-hidden className="flex">
            <GraphRail
                lane="var(--rail)"
                endsWithDot
                node={
                    <span className="size-12 rounded-full border-4 border-card bg-muted motion-safe:animate-pulse" />
                }
            />
            <div className="flex min-w-0 flex-1 border-b py-3 pr-4 motion-safe:animate-pulse">
                <div className="flex-1">
                    <span className="mt-[5px] mb-[7px] block h-3 w-2/5 rounded bg-muted" />
                    <span className="my-[5px] block h-3 w-4/5 rounded bg-muted" />
                    <span className="my-[5px] block h-3 w-3/5 rounded bg-muted" />
                    <span className="mt-3 mb-[7px] block h-4 w-9 rounded-md bg-muted" />
                </div>
            </div>
        </div>
    );
}

export function EmptyFeed({
    command,
    children,
}: {
    command: string;
    children: ReactNode;
}) {
    return (
        <>
            <p className="font-mono text-[13px] text-muted-foreground">
                {command}
            </p>
            <div className="mx-auto mt-3 max-w-sm text-[15px] text-pretty">
                {children}
            </div>
        </>
    );
}
