import type { CancelToken, PageProps } from '@inertiajs/core';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { ArrowUp } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import LogController from '@/actions/App/Http/Controllers/LogController';
import { CommitComposer } from '@/components/commit-composer';
import { useCommitDialogClosedCount } from '@/components/commit-composer-dialog';
import { CommitFeed, EmptyFeed } from '@/components/commit-feed';
import { FollowButton } from '@/components/follow-button';
import { LinkifiedText } from '@/components/linkified-text';
import { OrbitRail } from '@/components/orbit-rail/orbit-rail';
import { PageTabs } from '@/components/page-tabs';
import { UserAvatar } from '@/components/user-avatar';
import { UserPreview } from '@/components/user-preview';
import { WhoToFollowInLog } from '@/components/who-to-follow';
import { useComposerVisibility } from '@/hooks/use-composer-visibility';
import { useLaneSwipe } from '@/hooks/use-lane-swipe';
import { useLastVisit } from '@/hooks/use-last-visit';
import AppLayout from '@/layouts/app-layout';
import { backgroundPollOptions } from '@/lib/background-poll';
import { emojify } from '@/lib/emoji';
import { cn } from '@/lib/utils';
import type { Commit, CursorPaginated, Log } from '@/types';

export default function Show({
    log,
    commits,
    isRanked,
    suggestions,
}: {
    log: Log;
    commits: CursorPaginated<Commit>;
    isRanked: boolean;
    suggestions: Commit[];
}) {
    const {
        props: { auth },
    } = usePage();
    const user = auth.user;
    const [notice, setNotice] = useState<{
        commits: Commit[];
        leaving: boolean;
    } | null>(null);
    const [stateLog, setStateLog] = useState(log);
    const loadRequest = useRef<CancelToken | null>(null);
    const loadingNew = useRef(false);
    const freshIds = useRef<Set<string>>(new Set());

    // Another log has its own commits to announce, so nothing of the last one's pill carries over.
    if (stateLog !== log) {
        setStateLog(log);
        setNotice(null);
    }

    const commitDialogClosedCount = useCommitDialogClosedCount();
    const viewerId = user?.id ?? 'guest';
    const newestId = isRanked ? undefined : commits.data[0]?.id;
    const oldestLoadedId = commits.data.at(-1)?.id;
    const tabsRef = useRef<HTMLDivElement>(null);
    const [tabsHeight, setTabsHeight] = useState(0);
    const feedRef = useRef<HTMLDivElement>(null);
    const followAnchor = useRef<{
        row: HTMLElement;
        top: number;
        scrollY: number;
    } | null>(null);
    const returningToTop = useRef(false);
    const nativeAnchoring = useRef<string | null>(null);
    const [composer, setComposer] = useState<HTMLDivElement | null>(null);
    useLaneSwipe(feedRef, log);
    useComposerVisibility(composer, tabsHeight);

    useLayoutEffect(() => {
        followAnchor.current = null;
        returningToTop.current = false;

        return () => {
            if (nativeAnchoring.current !== null) {
                document.documentElement.style.overflowAnchor =
                    nativeAnchoring.current;
                nativeAnchoring.current = null;
            }
        };
    }, [log]);

    useEffect(() => {
        const tabs = tabsRef.current;

        if (!tabs) {
            return;
        }

        const updateHeight = () =>
            setTabsHeight(tabs.getBoundingClientRect().height);
        updateHeight();

        const observer = new ResizeObserver(updateHeight);
        observer.observe(tabs);

        return () => observer.disconnect();
    }, []);

    const preserveReadingPosition = useCallback(
        (ids: Set<string>) => {
            if (returningToTop.current) return;
            const headerBottom =
                tabsRef.current?.getBoundingClientRect().bottom ?? 0;
            const focused = document.activeElement?.closest<HTMLElement>(
                'input, textarea, [contenteditable="true"]',
            );
            const rows =
                feedRef.current?.querySelectorAll<HTMLElement>(
                    '[data-commit]',
                ) ?? [];
            const row =
                focused &&
                composer?.contains(focused) &&
                focused.getBoundingClientRect().bottom > headerBottom &&
                focused.getBoundingClientRect().top < window.innerHeight
                    ? focused
                    : Array.from(rows).find(
                          (row) =>
                              ids.has(row.dataset.commit ?? '') &&
                              row.getBoundingClientRect().bottom > headerBottom,
                      );
            followAnchor.current = row
                ? {
                      row,
                      top: row.getBoundingClientRect().top,
                      scrollY: window.scrollY,
                  }
                : null;
            if (row && nativeAnchoring.current === null) {
                // Take ownership before insertion so the browser cannot also compensate for it.
                const style = document.documentElement.style;
                nativeAnchoring.current = style.overflowAnchor;
                style.overflowAnchor = 'none';
            }
        },
        [composer],
    );

    const restoreReadingPosition = useCallback(() => {
        if (returningToTop.current) return;
        const anchor = followAnchor.current;
        if (!anchor?.row.isConnected) return;

        const top = anchor.row.getBoundingClientRect().top;
        const scrollDelta = window.scrollY - anchor.scrollY;
        // Layout compensation must retain viewport movement, even when both change in one frame.
        const targetTop = anchor.top - scrollDelta;
        if (top !== targetTop) window.scrollBy(0, top - targetTop);
        anchor.top = targetTop;
        anchor.scrollY = window.scrollY;

        if (scrollDelta !== 0) {
            preserveReadingPosition(
                new Set(commits.data.map((commit) => commit.id)),
            );
        }
    }, [commits, preserveReadingPosition]);

    useLayoutEffect(() => {
        const feed = feedRef.current;
        if (!feed || isRanked) return;

        const observer = new ResizeObserver(restoreReadingPosition);
        observer.observe(feed);
        const mutations = new MutationObserver(restoreReadingPosition);
        mutations.observe(feed, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['style'],
        });
        const updateFocus = () => {
            if (followAnchor.current)
                preserveReadingPosition(
                    new Set(commits.data.map((commit) => commit.id)),
                );
        };
        let scrollTimer: ReturnType<typeof setTimeout> | undefined;
        const updateScroll = () => {
            if (!returningToTop.current) {
                restoreReadingPosition();
                return;
            }

            // Smooth scrolling owns the viewport until it finishes or the reader interrupts it.
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                returningToTop.current = false;
                preserveReadingPosition(
                    new Set(commits.data.map((commit) => commit.id)),
                );
            }, 150);
        };
        window.addEventListener('focusin', updateFocus);
        window.addEventListener('focusout', updateFocus);
        window.addEventListener('scroll', updateScroll, {
            passive: true,
        });
        if (returningToTop.current) updateScroll();

        return () => {
            observer.disconnect();
            mutations.disconnect();
            clearTimeout(scrollTimer);
            window.removeEventListener('focusin', updateFocus);
            window.removeEventListener('focusout', updateFocus);
            window.removeEventListener('scroll', updateScroll);
        };
    }, [commits, isRanked, preserveReadingPosition, restoreReadingPosition]);

    useEffect(
        () =>
            router.on('beforeUpdate', ({ detail: { page } }) => {
                if (
                    (log !== 'following' && !loadingNew.current) ||
                    page.component !== 'log/show' ||
                    !page.scrollProps?.commits?.reset
                ) {
                    return;
                }

                const incoming = page.props.commits as CursorPaginated<Commit>;

                if (incoming === commits) {
                    return;
                }

                if (loadingNew.current) {
                    const known = new Set(commits.data.map((c) => c.id));
                    freshIds.current = new Set([
                        ...freshIds.current,
                        ...incoming.data
                            .map((commit) => commit.id)
                            .filter((id) => !known.has(id)),
                    ]);
                }

                preserveReadingPosition(
                    new Set(incoming.data.map((commit) => commit.id)),
                );
            }),
        [log, commits, preserveReadingPosition],
    );

    useLayoutEffect(restoreReadingPosition, [restoreReadingPosition]);

    useLayoutEffect(() => {
        const ids = freshIds.current;

        if (ids.size === 0) {
            return;
        }

        const rows = Array.from(
            feedRef.current?.querySelectorAll<HTMLElement>('[data-commit]') ??
                [],
        ).filter((row) => ids.has(row.dataset.commit ?? ''));
        rows.filter((row) => row.dataset.fresh === undefined).forEach((row) => {
            row.dataset.fresh = '';
        });

        // They stay lit until they are on screen, then while nobody is touching the page, and fade soon after the pointer
        // starts moving. Commits that arrived while the reader was further down wait for them to scroll back up.
        let timer: ReturnType<typeof setTimeout> | undefined;
        let sightedAt = 0;
        let hurried = false;
        const visible = new Set<Element>();

        const settle = () => {
            rows.forEach((row) => row.removeAttribute('data-fresh'));
            freshIds.current = new Set();
            cleanup();
        };

        const hurry = () => {
            if (hurried || sightedAt === 0 || Date.now() - sightedAt < 700) {
                return;
            }

            hurried = true;
            clearTimeout(timer);
            timer = setTimeout(settle, 1800);
        };

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) =>
                    entry.isIntersecting
                        ? visible.add(entry.target)
                        : visible.delete(entry.target),
                );

                if (visible.size > 0 && sightedAt === 0) {
                    sightedAt = Date.now();
                    timer = setTimeout(settle, 7000);
                }
            },
            { threshold: 0.6 },
        );

        rows.forEach((row) => observer.observe(row));

        function cleanup() {
            clearTimeout(timer);
            observer.disconnect();
            window.removeEventListener('pointermove', hurry);
            window.removeEventListener('wheel', hurry);
            window.removeEventListener('keydown', hurry);
        }

        window.addEventListener('pointermove', hurry, { passive: true });
        window.addEventListener('wheel', hurry, { passive: true });
        window.addEventListener('keydown', hurry);

        return cleanup;
    }, [commits]);

    const firstSeenId = useLastVisit(viewerId, log, commits.data);

    const loadNewest = (count: number) => {
        if (isRanked || !count || loadingNew.current) {
            return;
        }

        const known = new Set(commits.data.map((commit) => commit.id));
        loadingNew.current = true;
        router.reload(
            backgroundPollOptions({
                only: ['commits', 'newCommitsCount'],
                reset: ['commits'],
                data: { since: '' },
                // Retain the loaded range so inserting new commits preserves the reader's row.
                headers: { 'X-Log-Through': oldestLoadedId ?? '' },
                preserveUrl: true,
                onCancelToken: (token) => {
                    loadRequest.current = token;
                },
                onSuccess: (page) => {
                    const incoming = page.props
                        .commits as CursorPaginated<Commit>;
                    const arrivals = incoming.data.filter(
                        (commit) =>
                            !known.has(commit.id) &&
                            commit.user_id !== user?.id,
                    );
                    if (
                        arrivals.length === 0 ||
                        (document.visibilityState === 'visible' &&
                            window.scrollY <= 0)
                    )
                        return;

                    setNotice((current) => ({
                        commits: [
                            ...arrivals,
                            ...(current?.leaving
                                ? []
                                : (current?.commits ?? [])
                            ).filter((commit) =>
                                incoming.data.some(
                                    (row) => row.id === commit.id,
                                ),
                            ),
                        ],
                        leaving: false,
                    }));
                },
                onFinish: () => {
                    loadingNew.current = false;
                    loadRequest.current = null;
                },
            })(),
        );
    };

    const { stop: stopPolling } = usePoll(
        30_000,
        backgroundPollOptions(() => ({
            only: ['newCommitsCount', 'removedCommitIds'],
            data: {
                since: newestId,
                pending: notice?.leaving
                    ? []
                    : (notice?.commits.map((commit) => commit.id) ?? []),
            },
            preserveUrl: true,
            onSuccess: (page) => {
                const removed = new Set(
                    page.props.removedCommitIds as string[],
                );
                if (removed.size > 0) {
                    setNotice((current) => {
                        if (!current || current.leaving) return current;
                        const remaining = current.commits.filter(
                            (commit) => !removed.has(commit.id),
                        );
                        return remaining.length > 0
                            ? { ...current, commits: remaining }
                            : { ...current, leaving: true };
                    });
                    router.replaceProp(
                        'commits',
                        (value: unknown, props: PageProps) => {
                            if (props.log !== log) return value;
                            const current = value as CursorPaginated<Commit>;
                            const retained = current.data.filter(
                                (commit) => !removed.has(commit.id),
                            );
                            preserveReadingPosition(
                                new Set(retained.map((commit) => commit.id)),
                            );
                            return { ...current, data: retained };
                        },
                    );
                }
                loadNewest(page.props.newCommitsCount as number);
            },
        })),
        { autoStart: newestId !== undefined },
    );

    // Showing a log remembers it, so pages prefetched before it still carry the previous log in their shared props.
    useEffect(() => {
        router.flushAll();
    }, [log]);

    useEffect(
        () =>
            router.on('location', (event) => {
                if (event.detail.versionChange) {
                    stopPolling();
                }
            }),
        [stopPolling],
    );

    const dismissNotice = useCallback(() => {
        setNotice((current) => current && { ...current, leaving: true });
    }, []);

    const noticeLeaving = notice?.leaving;
    useEffect(() => {
        if (!noticeLeaving) return;
        const timer = setTimeout(() => setNotice(null), 450);
        return () => clearTimeout(timer);
    }, [noticeLeaving]);

    const noticeNewestId = notice?.commits[0]?.id;
    useEffect(() => {
        if (!noticeNewestId) return;
        const row = Array.from(
            feedRef.current?.querySelectorAll<HTMLElement>('[data-commit]') ??
                [],
        ).find((row) => row.dataset.commit === noticeNewestId);
        if (!row) return;

        const observer = new IntersectionObserver(
            (entries) => {
                if (
                    document.visibilityState === 'visible' &&
                    entries.some((entry) => entry.intersectionRatio >= 0.6)
                )
                    dismissNotice();
            },
            {
                rootMargin: `-${tabsHeight}px 0px 0px 0px`,
                threshold: 0.6,
            },
        );
        const update = () => {
            observer.disconnect();
            if (document.visibilityState === 'visible') observer.observe(row);
        };
        update();
        document.addEventListener('visibilitychange', update);

        return () => {
            observer.disconnect();
            document.removeEventListener('visibilitychange', update);
        };
    }, [noticeNewestId, commits, tabsHeight, dismissNotice]);

    useEffect(
        () => () => {
            loadRequest.current?.cancel();
        },
        [log],
    );

    useEffect(() => {
        const update = () => {
            if (document.visibilityState === 'visible' && window.scrollY <= 0)
                dismissNotice();
        };
        window.addEventListener('scroll', update, { passive: true });
        document.addEventListener('visibilitychange', update);

        return () => {
            window.removeEventListener('scroll', update);
            document.removeEventListener('visibilitychange', update);
        };
    }, [dismissNotice]);

    const authors = [
        ...new Map(
            notice?.commits.map((commit) => [commit.user.id, commit.user]),
        ).values(),
    ];
    const announcementLabel = `${notice?.commits.length ?? 0} new ${notice?.commits.length === 1 ? 'commit' : 'commits'} above. Scroll to top`;

    return (
        <>
            <Head title={titles[log]} />

            <div
                ref={tabsRef}
                className="sticky top-0 z-10 bg-card [view-transition-class:tab-header] [view-transition-name:log-header]"
            >
                <h1 className="sr-only">{titles[log]}</h1>
                <PageTabs
                    label="Logs"
                    prefetch={false}
                    tabs={[
                        {
                            title: 'Following',
                            href: LogController.show('following'),
                            isActive: log === 'following',
                        },
                        {
                            title: 'Latest',
                            href: LogController.show('latest'),
                            isActive: log === 'latest',
                        },
                        {
                            title: 'Trending',
                            href: LogController.show('trending'),
                            isActive: log === 'trending',
                        },
                    ]}
                />
            </div>

            {user !== null && <OrbitRail />}

            {user !== null && (
                <div ref={setComposer}>
                    <CommitComposer key={commitDialogClosedCount} />
                </div>
            )}

            {notice !== null && (
                <div
                    className="pointer-events-none sticky z-10 flex h-0 items-start justify-center"
                    style={{ top: tabsHeight }}
                >
                    <button
                        type="button"
                        onClick={() => {
                            returningToTop.current = true;
                            followAnchor.current = null;
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        }}
                        aria-label={announcementLabel}
                        title={announcementLabel}
                        className={cn(
                            notice.leaving && '-translate-y-2 opacity-0',
                            'pointer-events-auto mt-3 flex min-h-11 max-w-[calc(100vw-2rem)] items-center justify-center gap-1.5 rounded-md bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground shadow-[0_4px_14px_-4px_rgb(0_0_0/0.35)] transition-colors hover:bg-primary-hover motion-safe:animate-in motion-safe:transition-[opacity,translate,background-color] motion-safe:duration-450 motion-safe:fade-in motion-safe:slide-in-from-top-2',
                        )}
                    >
                        <ArrowUp className="size-4 shrink-0" />
                        <span
                            aria-hidden
                            className="flex shrink-0 items-center -space-x-1 [clip-path:inset(-2px)] motion-safe:transition-[width] motion-safe:duration-250 motion-safe:ease-out"
                            style={{
                                width:
                                    24 + (Math.min(authors.length, 4) - 1) * 20,
                            }}
                        >
                            {authors.slice(0, 3).map((author) => (
                                <span
                                    key={author.id}
                                    className="shrink-0 motion-safe:animate-in motion-safe:duration-250 motion-safe:fade-in"
                                >
                                    <UserAvatar
                                        user={author}
                                        pixels={48}
                                        className="size-6 ring-2 ring-primary"
                                    />
                                </span>
                            ))}
                            {authors.length > 3 && (
                                <span
                                    title={`${authors.length - 3} more authors`}
                                    className="relative grid size-6 shrink-0 place-items-center rounded-full bg-muted pt-0.5 font-mono text-[10px] leading-none font-medium text-foreground tabular-nums ring-2 ring-primary motion-safe:animate-in motion-safe:duration-250 motion-safe:fade-in"
                                >
                                    {authors.length - 3 > 99
                                        ? '99+'
                                        : `+${authors.length - 3}`}
                                </span>
                            )}
                        </span>
                        <span className="min-w-0 truncate">New commits</span>
                    </button>
                </div>
            )}

            <div
                ref={feedRef}
                className="[view-transition-class:tab-content] [view-transition-name:log-feed]"
            >
                <CommitFeed
                    commits={commits}
                    firstSeenId={firstSeenId}
                    isRanked={isRanked}
                    isBelowComposer={user !== null}
                    end="That's the root commit. There's nothing before it."
                    interlude={() => <WhoToFollowInLog />}
                    empty={
                        log === 'following' ? (
                            <EmptyFeed command="fatal: your log does not have any commits yet">
                                Your log shows your commits and commits from
                                people you follow.{' '}
                                {suggestions.length > 0
                                    ? 'Here is what a few developers committed lately:'
                                    : 'Find someone worth following on '}
                                {suggestions.length === 0 && (
                                    <>
                                        <Link
                                            href={LogController.show(
                                                'trending',
                                            )}
                                            className="font-medium text-primary underline"
                                        >
                                            Trending
                                        </Link>
                                        .
                                    </>
                                )}
                                {suggestions.length > 0 && (
                                    <Suggestions suggestions={suggestions} />
                                )}
                            </EmptyFeed>
                        ) : (
                            <EmptyFeed command="fatal: no commits yet">
                                Nobody has committed anything. Be the first.
                                It's just plain text; you'll be fine.
                            </EmptyFeed>
                        )
                    }
                />
            </div>
        </>
    );
}

const titles: Record<Log, string> = {
    following: 'Following',
    latest: 'Latest',
    trending: 'Trending',
};

function Suggestions({ suggestions }: { suggestions: Commit[] }) {
    return (
        <>
            <ul className="mt-5 divide-y rounded-lg border text-left">
                {suggestions.map((commit) => (
                    <li key={commit.id} className="flex items-center gap-3 p-3">
                        <UserPreview user={commit.user} className="shrink-0">
                            <UserAvatar user={commit.user} className="size-9" />
                        </UserPreview>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm">
                                <UserPreview user={commit.user}>
                                    <span className="font-semibold">
                                        {commit.user.name}
                                    </span>
                                </UserPreview>{' '}
                                <span className="font-mono text-[13px] text-muted-foreground">
                                    @{commit.user.username}
                                </span>
                            </p>
                            <p className="truncate text-sm text-muted-foreground">
                                <LinkifiedText
                                    text={emojify(commit.body)}
                                    mentions={commit.mentions}
                                    startsText
                                />
                            </p>
                        </div>
                        <FollowButton
                            user={commit.user}
                            isFollowing={false}
                            size="sm"
                            className="min-w-24 shrink-0"
                        />
                    </li>
                ))}
            </ul>
            <p className="mt-4 text-sm text-muted-foreground">
                Or browse{' '}
                <Link
                    href={LogController.show('trending')}
                    className="font-medium text-primary underline"
                >
                    Trending
                </Link>{' '}
                for more.
            </p>
        </>
    );
}

Show.layout = AppLayout;
