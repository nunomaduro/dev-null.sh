import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useLayoutEffect } from 'react';
import LogController from '@/actions/App/Http/Controllers/LogController';
import { CommitCard, CommitTombstone } from '@/components/commit-card';
import { CopyLinkButton } from '@/components/copy-link-button';
import { GraphCanvas } from '@/components/graph-canvas';
import { useContinuation } from '@/hooks/use-continuation';
import AppLayout from '@/layouts/app-layout';
import { scrollFirstPageToFocusedCommit } from '@/lib/focused-commit-scroll';
import { isDeleted, laneOf, shortHash } from '@/lib/utils';
import { home } from '@/routes';
import type {
    Commit,
    CursorPaginated,
    ThreadCommit,
    ThreadContinuation,
} from '@/types';

const titleLength = 60;

export default function Show({
    commit,
    ancestors,
    continuation,
    replies,
}: {
    commit: Commit;
    ancestors: ThreadCommit[];
    continuation: ThreadContinuation;
    replies: CursorPaginated<ThreadCommit> | null;
}) {
    const { auth } = usePage().props;
    const { shown, nextId, size, isCapped, isLoading, loadMore } =
        useContinuation(continuation);
    const focusedPosition = continuation.position;
    const positionAt = (position: number) =>
        size > 1 && position >= 1 ? { position, size, isCapped } : undefined;

    const excerpt =
        commit.body.length > titleLength
            ? `${commit.body.slice(0, titleLength).trimEnd()}…`
            : commit.body;

    const goBack = () => {
        if (window.history.length > 1) {
            window.history.back();

            return;
        }

        router.visit(auth.log === null ? home() : LogController.show(auth.log));
    };

    const lastAncestor = ancestors.at(-1);

    // Runs before the first paint of a fresh load; later visits are scrolled by trackFocusedCommitScroll.
    useLayoutEffect(() => {
        scrollFirstPageToFocusedCommit(commit.id, ancestors.length);
        // Only the first render of the page matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <>
            <Head title={`${commit.user.name}: “${excerpt}”`} />

            <div className="sticky top-0 z-10 flex h-14 items-center gap-3 border-b bg-card px-4">
                <button
                    type="button"
                    onClick={goBack}
                    aria-label="Back"
                    className="text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                </button>
                <h1 className="font-semibold">Commit</h1>
                <p className="ml-auto shrink-0 font-mono text-[13px] text-muted-foreground">
                    {shortHash(commit.id)}
                </p>
                <CopyLinkButton commit={commit} className="-mr-1 -ml-1.5" />
            </div>

            <GraphCanvas>
                {ancestors.map((ancestor, index) => {
                    const previous = ancestors[index - 1];
                    const previousLane = previous
                        ? laneOf(previous)
                        : undefined;

                    return isDeleted(ancestor) ? (
                        <CommitTombstone
                            key={ancestor.id}
                            id={ancestor.id}
                            previousLane={previousLane}
                            graphAfter={previous?.id}
                        />
                    ) : (
                        <CommitCard
                            key={ancestor.id}
                            commit={ancestor}
                            previousLane={previousLane}
                            graphAfter={previous?.id}
                            isGroupedWithPrevious={isSameAuthor(
                                previous,
                                ancestor,
                            )}
                            canDelete={false}
                            threadPosition={positionAt(
                                focusedPosition - (ancestors.length - index),
                            )}
                        />
                    );
                })}

                <CommitCard
                    key={commit.id}
                    commit={commit}
                    previousLane={
                        lastAncestor ? laneOf(lastAncestor) : undefined
                    }
                    graphAfter={lastAncestor?.id}
                    isGroupedWithPrevious={isSameAuthor(lastAncestor, commit)}
                    initialReplies={replies ?? undefined}
                    exceptReplyId={shown[0]?.id}
                    endsWithDot={shown.length === 0}
                    threadPosition={positionAt(focusedPosition)}
                    isFocused
                />

                {ancestors.length > 0 && (
                    <script
                        // A server-rendered page is painted before the app runs, so the browser is told to scroll to the
                        // commit as soon as it has parsed it, unless it is restoring a position after a reload or going back.
                        dangerouslySetInnerHTML={{
                            __html: scrollWhileParsing(commit.id),
                        }}
                    />
                )}

                {shown.map((next, index) => {
                    const previous = shown[index - 1] ?? commit;
                    const following = shown[index + 1];
                    const isLast = following === undefined;
                    const exceptReplyId = following?.id ?? nextId ?? undefined;

                    return (
                        <CommitCard
                            key={next.id}
                            commit={next}
                            previousLane={laneOf(previous)}
                            graphAfter={previous.id}
                            isGroupedWithPrevious
                            exceptReplyId={exceptReplyId}
                            loadsReplies={isLast && nextId === null}
                            endsWithDot={isLast && nextId === null}
                            threadPosition={positionAt(
                                focusedPosition + index + 1,
                            )}
                        />
                    );
                })}

                {nextId !== null && (
                    <div className="pr-4 pl-16 sm:pl-18">
                        <div className="border-b py-3">
                            <button
                                type="button"
                                onClick={loadMore}
                                aria-disabled={isLoading}
                                className="-ml-2 min-w-44 rounded-md px-2 py-0.5 text-left text-[13px] text-muted-foreground transition-colors [overflow-anchor:none] hover:bg-accent hover:text-foreground aria-disabled:pointer-events-none"
                                data-test="more-thread-button"
                            >
                                {isLoading ? (
                                    <span role="status">
                                        Loading the thread…
                                    </span>
                                ) : (
                                    'Show more of this thread'
                                )}
                            </button>
                        </div>
                    </div>
                )}
            </GraphCanvas>
        </>
    );
}

function scrollWhileParsing(id: string): string {
    const selector = JSON.stringify(`[data-commit="${id}"]`);

    return `(function(){var l=performance.getEntriesByType('navigation')[0];if(l&&l.type!=='navigate')return;var c=document.querySelector(${selector});if(c)c.scrollIntoView({block:'start',behavior:'instant'});})();`;
}

function isSameAuthor(
    previous: ThreadCommit | undefined,
    commit: Commit,
): boolean {
    return (
        previous !== undefined &&
        !isDeleted(previous) &&
        previous.user_id === commit.user_id
    );
}

Show.layout = AppLayout;
