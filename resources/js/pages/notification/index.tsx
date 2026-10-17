import type { CancelToken } from '@inertiajs/core';
import { Head, InfiniteScroll, Link, router, usePoll } from '@inertiajs/react';
import {
    ArrowUp,
    AtSign,
    Check,
    ChevronDown,
    MessageSquareReply,
    Quote,
    Star,
    UserPlus,
    X,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import {
    Fragment,
    useEffect,
    useEffectEvent,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import { index } from '@/actions/App/Http/Controllers/NotificationController';
import { show as groupShow } from '@/actions/App/Http/Controllers/NotificationGroupController';
import { CommitBodyExcerpt } from '@/components/commit-body';
import { EmptyFeed } from '@/components/commit-feed';
import { FollowButton } from '@/components/follow-button';
import { GraphRail, RailSegment } from '@/components/graph-rail';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { useNow } from '@/hooks/use-now';
import { useUser } from '@/hooks/use-user';
import AppLayout from '@/layouts/app-layout';
import {
    cn,
    formatDayLabel,
    formatRelativeTime,
    laneColor,
    localDayKey,
} from '@/lib/utils';
import { show } from '@/routes/user';
import type { CursorPaginated, Notification, NotificationGroup } from '@/types';

type Filters = {
    type:
        | 'all'
        | 'replies'
        | 'quotes'
        | 'stars'
        | 'mentions'
        | 'follows'
        | 'moderation';
};

const filterOptions: {
    type: Filters['type'];
    label: string;
    empty: string;
}[] = [
    {
        type: 'all',
        label: 'All',
        empty: 'Nothing here yet. Follows, stars, replies, quotes, mentions, and moderation updates for your commits will show up here.',
    },
    {
        type: 'replies',
        label: 'Replies',
        empty: 'No replies yet. When someone replies to one of your commits, it shows up here.',
    },
    {
        type: 'quotes',
        label: 'Quotes',
        empty: 'No quotes yet. When someone quotes one of your commits, it shows up here.',
    },
    {
        type: 'stars',
        label: 'Stars',
        empty: 'No stars yet. When someone stars one of your commits, it shows up here.',
    },
    {
        type: 'mentions',
        label: 'Mentions',
        empty: 'No mentions yet. When someone mentions you in a commit, it shows up here.',
    },
    {
        type: 'follows',
        label: 'Follows',
        empty: 'No followers yet. When someone follows you, it shows up here.',
    },
    {
        type: 'moderation',
        label: 'Moderation',
        empty: 'No moderation updates. Approved and rejected commits show up here.',
    },
];

const rail = 'var(--rail)';

const isNewSince = (
    notification: Notification | NotificationGroup,
    readAt: string,
): boolean =>
    notification.read_at === null ||
    Date.parse(notification.read_at) >= Date.parse(readAt);

type PollAnchor = { id: string; top: number; position: number };

function firstVisibleGroup(
    header: HTMLElement | null,
    groups: NotificationGroup[],
): PollAnchor | null {
    const headerBottom = header?.getBoundingClientRect().bottom ?? 0;

    for (const element of document.querySelectorAll<HTMLElement>(
        '[data-notification-group]',
    )) {
        const { top, bottom } = element.getBoundingClientRect();
        const id = element.dataset.notificationGroup ?? '';

        if (bottom > headerBottom) {
            return {
                id,
                top,
                position: groups.findIndex((group) => group.group_id === id),
            };
        }
    }

    return null;
}

export default function Index({
    notifications,
    filters,
    readAt,
}: {
    notifications: CursorPaginated<NotificationGroup>;
    filters: Filters;
    readAt: string;
}) {
    const now = useNow();
    const pollRequest = useRef<CancelToken | null>(null);
    const filterNav = useRef<HTMLElement>(null);
    const header = useRef<HTMLDivElement>(null);
    const pollAnchor = useRef<PollAnchor | null>(null);
    const [arrivals, setArrivals] = useState(0);
    const { start, stop } = usePoll(
        30_000,
        () => ({
            only: ['notifications', 'auth.unreadNotificationsCount'],
            reset: ['notifications'],
            data: {
                cursor: '',
                through: notifications.data.at(-1)?.created_at,
            },
            preserveUrl: true,
            preserveErrors: true,
            onStart: () => {
                pollAnchor.current = firstVisibleGroup(
                    header.current,
                    notifications.data,
                );
            },
            onCancelToken: (token) => {
                pollRequest.current = token;
            },
            onFinish: () => {
                pollRequest.current = null;
            },
        }),
        { mode: 'rest', background: 'pause' },
    );
    const filterVisit = useRef(0);
    const filterLink = (type: Filters['type']) => {
        const href = index({ query: type === 'all' ? {} : { type } });

        return {
            href: href.url,
            onClick: (event: MouseEvent<HTMLAnchorElement>) => {
                if (
                    event.metaKey ||
                    event.ctrlKey ||
                    event.shiftKey ||
                    event.altKey ||
                    event.button !== 0
                ) {
                    return;
                }

                event.preventDefault();
                const visit = ++filterVisit.current;
                stop();
                pollRequest.current?.cancel();
                pollAnchor.current = null;
                setArrivals(0);
                router.visit(href, {
                    only: ['notifications', 'filters', 'auth'],
                    reset: ['notifications'],
                    preserveState: true,
                    viewTransition: true,
                    onFinish: () => {
                        if (visit === filterVisit.current) {
                            start();
                        }
                    },
                });
            },
        };
    };
    const active =
        filterOptions.find((option) => option.type === filters.type) ??
        filterOptions[0];

    useLayoutEffect(() => {
        const anchor = pollAnchor.current;
        pollAnchor.current = null;
        const element =
            anchor &&
            document.querySelector(`[data-notification-group="${anchor.id}"]`);

        if (!anchor || !element) {
            return;
        }

        window.scrollBy(0, element.getBoundingClientRect().top - anchor.top);

        const arrived =
            notifications.data.findIndex(
                (group) => group.group_id === anchor.id,
            ) - anchor.position;

        if (anchor.position >= 0 && arrived > 0) {
            setArrivals((count) => count + arrived);
        }
    }, [notifications.data]);

    useEffect(() => {
        if (arrivals === 0) {
            return;
        }

        const clearAtTop = () => {
            if (window.scrollY === 0) {
                setArrivals(0);
            }
        };

        window.addEventListener('scroll', clearAtTop, { passive: true });

        return () => window.removeEventListener('scroll', clearAtTop);
    }, [arrivals]);

    useEffect(() => {
        const nav = filterNav.current;
        const tab = nav?.querySelector<HTMLElement>('[aria-current="page"]');

        if (nav && tab) {
            nav.scrollLeft =
                tab.offsetLeft - (nav.clientWidth - tab.offsetWidth) / 2;
        }
    }, [filters.type]);

    return (
        <>
            <Head title="Notifications" />
            <div ref={header} className="sticky top-0 z-10 border-b bg-card">
                <div className="flex h-14 items-center px-4 sm:px-6">
                    <h1 className="font-semibold">Notifications</h1>
                </div>
                <nav
                    ref={filterNav}
                    aria-label="Filter notifications"
                    className="flex [scrollbar-width:none] overflow-x-auto px-1 sm:px-3"
                >
                    {filterOptions.map((option) => {
                        const isActive = option.type === active.type;

                        return (
                            <a
                                key={option.type}
                                {...filterLink(option.type)}
                                aria-current={isActive ? 'page' : undefined}
                                className={cn(
                                    'shrink-0 px-3 text-[15px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-offset-[-2px]',
                                    { 'text-foreground': isActive },
                                )}
                            >
                                <span className="relative block py-3">
                                    {option.label}
                                    {isActive && (
                                        <span
                                            aria-hidden
                                            className="absolute inset-x-0 bottom-0 h-[3px] bg-primary [view-transition-class:tab-indicator]"
                                            style={{
                                                viewTransitionName:
                                                    'tab-indicator-notifications',
                                            }}
                                        />
                                    )}
                                </span>
                            </a>
                        );
                    })}
                </nav>
                {arrivals > 0 && (
                    <div className="pointer-events-none absolute inset-x-0 top-full flex justify-center pt-3">
                        <button
                            type="button"
                            onClick={() => {
                                setArrivals(0);
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                            }}
                            className="pointer-events-auto flex items-center gap-1.5 rounded-full bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground shadow-[0_4px_14px_-4px_rgb(0_0_0/0.35)] transition-colors hover:bg-primary-hover motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-top-2"
                        >
                            <ArrowUp aria-hidden className="size-4" />
                            <span className="tabular-nums">
                                {arrivals}
                            </span>{' '}
                            {arrivals === 1
                                ? 'new notification'
                                : 'new notifications'}
                        </button>
                    </div>
                )}
            </div>
            {notifications.data.length === 0 ? (
                <div className="px-6 py-16 text-center sm:px-12">
                    <EmptyFeed
                        command={
                            active.type === 'all'
                                ? '$ cat /dev/notifications'
                                : `$ cat /dev/notifications | grep ${active.type}`
                        }
                    >
                        {active.empty}
                        {active.type !== 'all' && (
                            <a
                                {...filterLink('all')}
                                className="mt-3 block text-primary hover:underline"
                            >
                                Show all notifications
                            </a>
                        )}
                    </EmptyFeed>
                </div>
            ) : (
                <>
                    <InfiniteScroll
                        data="notifications"
                        onlyNext
                        preserveUrl
                        loading={
                            <div
                                role="status"
                                aria-label="Loading more notifications"
                                className="animate-in opacity-0 delay-300 fill-mode-forwards fade-in"
                            >
                                <NotificationSkeleton hasPrevious />
                                <NotificationSkeleton hasPrevious />
                            </div>
                        }
                    >
                        {notifications.data.map((notification, position) => {
                            const previous = notifications.data[position - 1];
                            const isNew = isNewSince(notification, readAt);
                            const startsDay =
                                !previous ||
                                localDayKey(previous.created_at) !==
                                    localDayKey(notification.created_at);

                            return (
                                <Fragment key={notification.group_id}>
                                    {previous &&
                                        isNewSince(previous, readAt) &&
                                        !isNew && <LastVisitMarker />}
                                    {startsDay && (
                                        <DayMarker
                                            label={formatDayLabel(
                                                notification.created_at,
                                                now,
                                            )}
                                            hasPrevious={previous !== undefined}
                                        />
                                    )}
                                    <NotificationGroupItem
                                        key={
                                            filters.type +
                                            '-' +
                                            notification.group_id
                                        }
                                        notification={notification}
                                        type={filters.type}
                                        readAt={readAt}
                                        isNew={isNew}
                                        hasPrevious={previous !== undefined}
                                    />
                                </Fragment>
                            );
                        })}
                    </InfiniteScroll>
                    {notifications.next_cursor === null && (
                        <div className="flex">
                            <GraphRail
                                lane={rail}
                                previousLane={rail}
                                variant="root"
                                hasTail={false}
                            />
                            <p className="pt-[1.3rem] pr-4 pb-10 text-sm text-muted-foreground">
                                That's the oldest notification. There's nothing
                                before it.
                            </p>
                        </div>
                    )}
                </>
            )}
        </>
    );
}

function DayMarker({
    label,
    hasPrevious,
}: {
    label: string;
    hasPrevious: boolean;
}) {
    return (
        <div className="flex">
            <RailSegment lane={hasPrevious ? rail : undefined} />
            <div className="flex flex-1 items-center border-b py-2 pr-4">
                <h2 className="font-mono text-xs text-muted-foreground">
                    {label}
                </h2>
            </div>
        </div>
    );
}

function LastVisitMarker() {
    return (
        <div
            role="separator"
            aria-label="Notifications above are new since your last visit"
            className="flex"
        >
            <RailSegment lane={rail} />
            <div className="flex flex-1 items-center gap-3 border-b py-2 pr-4">
                <span className="font-mono text-xs text-muted-foreground">
                    HEAD@{'{last visit}'}
                </span>
                <span className="flex-1 border-t border-dashed" />
            </div>
        </div>
    );
}

function NotificationSkeleton({ hasPrevious }: { hasPrevious: boolean }) {
    return (
        <div aria-hidden className="flex">
            <GraphRail
                lane={rail}
                previousLane={hasPrevious ? rail : undefined}
                node={
                    <span className="size-9 rounded-full border-4 border-card bg-muted motion-safe:animate-pulse" />
                }
            />
            <div className="min-w-0 flex-1 border-b py-3 pr-4 motion-safe:animate-pulse">
                <div className="flex h-10 items-center">
                    <span className="size-8 rounded-full bg-muted" />
                </div>
                <span className="mt-[9px] mb-[7px] block h-3 w-1/2 rounded bg-muted" />
                <span className="my-[7px] block h-3 w-4/5 rounded bg-muted" />
            </div>
        </div>
    );
}

function groupNoun(notification: NotificationGroup): string {
    if (notification.type === 'followed') {
        return 'followers';
    }

    if (notification.type === 'starred') {
        return 'stars';
    }

    return 'updates';
}

function NotificationGroupItem({
    notification,
    type,
    readAt,
    isNew,
    hasPrevious,
}: {
    notification: NotificationGroup;
    type: Filters['type'];
    readAt: string;
    isNew: boolean;
    hasPrevious: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Notification[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [isSlow, setIsSlow] = useState(false);
    const [failed, setFailed] = useState(false);
    const id = useId();
    const grouped = notification.event_count > 1;
    const noun = groupNoun(notification);

    const load = async (next: string | null = null, prepend = false) => {
        setLoading(true);
        setFailed(false);
        const slow = window.setTimeout(() => setIsSlow(true), 300);
        try {
            const response = await fetch(
                groupShow(notification, { query: { type, cursor: next } }).url,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok) throw new Error('Unable to load activity');
            const data =
                (await response.json()) as CursorPaginated<Notification>;
            if (prepend) {
                setItems((previous) => {
                    const seen = new Set(previous.map((item) => item.id));

                    return [
                        ...data.data.filter((item) => !seen.has(item.id)),
                        ...previous,
                    ];
                });
            } else {
                setItems((previous) =>
                    next ? [...previous, ...data.data] : data.data,
                );
                setCursor(data.next_cursor);
            }
        } catch {
            setFailed(true);
        } finally {
            window.clearTimeout(slow);
            setIsSlow(false);
            setLoading(false);
        }
    };

    // A poll can change this group. An open list stays open: new events go on top, anything else reloads it.
    const eventCount = useRef(notification.event_count);
    const onEventCountChange = useEffectEvent(() => {
        const grew = notification.event_count > eventCount.current;
        eventCount.current = notification.event_count;

        if (open && items.length > 0) {
            void load(null, grew);

            return;
        }

        setItems([]);
        setCursor(null);
    });

    useEffect(() => {
        if (eventCount.current !== notification.event_count) {
            onEventCountChange();
        }
    }, [notification.event_count]);

    return (
        <article
            data-notification-group={notification.group_id}
            className="transition-colors has-[[data-notification-link]:focus-visible]:bg-accent/50 has-[[data-notification-link]:hover]:bg-accent/40"
        >
            <NotificationContent
                notification={notification}
                isNew={isNew}
                hasPrevious={hasPrevious}
                summary
            >
                {grouped && (
                    <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={id}
                        onClick={() => {
                            setOpen(!open);
                            if (!open && items.length === 0) void load();
                        }}
                        className="mt-1.5 -ml-2 flex items-center gap-1 rounded-md px-2 py-0.5 text-[13px] font-medium text-primary transition-colors hover:bg-primary-soft"
                    >
                        <ChevronDown
                            aria-hidden
                            className={cn(
                                'size-4 transition-transform motion-reduce:transition-none',
                                { '-rotate-90': !open },
                            )}
                        />
                        <span className="grid">
                            <span
                                className={cn(
                                    'col-start-1 row-start-1 tabular-nums',
                                    { invisible: open },
                                )}
                            >
                                {notification.event_count} {noun}
                            </span>
                            <span
                                className={cn('col-start-1 row-start-1', {
                                    invisible: !open,
                                })}
                            >
                                Hide {noun}
                            </span>
                        </span>
                    </button>
                )}
                {open && (
                    <div id={id} className="relative mt-2">
                        <span
                            aria-hidden
                            className="absolute top-0 bottom-2 left-3.5 border-l border-rail"
                        />
                        <ul>
                            {items.map((item) => (
                                <li key={item.id} className="py-2 pl-8">
                                    <NotificationContent
                                        notification={item}
                                        isNew={isNewSince(item, readAt)}
                                    />
                                </li>
                            ))}
                            {isSlow &&
                                Array.from(
                                    {
                                        length: Math.min(
                                            notification.event_count -
                                                items.length,
                                            3,
                                        ),
                                    },
                                    (_, row) => (
                                        <li
                                            key={row}
                                            aria-hidden
                                            className="flex h-11 items-center gap-2.5 pl-8 motion-safe:animate-pulse"
                                        >
                                            <span className="size-7 rounded-full bg-muted" />
                                            <span className="h-3 w-2/5 rounded bg-muted" />
                                        </li>
                                    ),
                                )}
                        </ul>
                        {loading && (
                            <p role="status" className="sr-only">
                                Loading {noun}…
                            </p>
                        )}
                        {failed && (
                            <div
                                role="alert"
                                className="flex flex-wrap items-center gap-3 py-2 pl-8"
                            >
                                <p className="font-mono text-[13px] text-muted-foreground">
                                    fatal: could not load {noun}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => void load(cursor)}
                                >
                                    Try again
                                </Button>
                            </div>
                        )}
                        {!loading && !failed && cursor && (
                            <button
                                type="button"
                                onClick={() => void load(cursor)}
                                className="mt-1 ml-6 flex items-center rounded-md px-2 py-0.5 text-[13px] font-medium text-primary transition-colors hover:bg-primary-soft"
                            >
                                Show older {noun}
                            </button>
                        )}
                    </div>
                )}
            </NotificationContent>
        </article>
    );
}

function FollowBackButton({ actor }: { actor: Notification['actor'] }) {
    const [isFollowing, setIsFollowing] = useState(actor.is_followed);

    return (
        <FollowButton
            user={actor}
            isFollowing={isFollowing}
            size="sm"
            className="min-w-24 shrink-0"
            only={['auth']}
            preserveState
            onSuccess={() => {
                setIsFollowing(!isFollowing);
                router.flushAll();
            }}
        />
    );
}

function NotificationContent({
    notification,
    summary = false,
    isNew = false,
    hasPrevious = false,
    children,
}: {
    notification: Notification | NotificationGroup;
    summary?: boolean;
    isNew?: boolean;
    hasPrevious?: boolean;
    children?: ReactNode;
}) {
    const now = useNow();
    const { user } = useUser();
    const { actor } = notification;
    const actors =
        summary && 'actors' in notification ? notification.actors : [actor];
    const actorsCount =
        summary && 'actors_count' in notification
            ? notification.actors_count
            : 1;
    const otherActors =
        notification.type === 'followed' || notification.type === 'starred'
            ? actorsCount - 1
            : 0;
    const moderation =
        notification.type === 'approved' || notification.type === 'rejected';
    const isOwnCommit = notification.type === 'starred' || moderation;
    const Icon = {
        followed: UserPlus,
        replied: MessageSquareReply,
        quoted: Quote,
        starred: Star,
        mentioned: AtSign,
        approved: Check,
        rejected: X,
    }[notification.type];
    const icon = (
        <Icon
            className={cn('size-4 shrink-0', {
                'text-muted-foreground': moderation,
                'fill-current': notification.type === 'starred',
            })}
            style={moderation ? undefined : { color: laneColor(actor.lane) }}
            aria-hidden
        />
    );
    const href =
        notification.type === 'followed'
            ? show(actor)
            : CommitController.show({
                  user: isOwnCommit ? user : actor,
                  commit: notification.commit,
              });
    const time = (
        <time
            dateTime={notification.created_at}
            title={new Date(notification.created_at).toLocaleString()}
            className="ml-auto min-w-[3ch] shrink-0 text-right font-mono text-[13px] text-muted-foreground"
        >
            {formatRelativeTime(notification.created_at, now)}
        </time>
    );
    const action = {
        followed: 'followed you',
        replied: 'replied to your commit',
        quoted: 'quoted your commit',
        starred: 'starred your commit',
        mentioned: 'mentioned you',
        approved: 'Your commit was approved',
        rejected: 'Your commit was rejected',
    }[notification.type];
    const excerpt =
        notification.type === 'followed' ? null : isOwnCommit ? (
            <div className="mt-2 rounded-lg border px-3 py-2 text-muted-foreground">
                <CommitBodyExcerpt
                    segments={notification.commit.body_segments}
                    className="max-h-[73px]"
                />
            </div>
        ) : (
            <CommitBodyExcerpt
                segments={notification.commit.body_segments}
                className="mt-1 max-h-40"
            />
        );
    const reason = notification.type === 'rejected' && (
        <div className="mt-2 text-sm text-muted-foreground">
            {notification.reason && (
                <p className="wrap-anywhere whitespace-pre-wrap text-foreground">
                    <span className="font-mono text-[13px] text-muted-foreground">
                        reason:
                    </span>{' '}
                    {notification.reason}
                </p>
            )}
            <p className="mt-1">
                Only you can see this update and your rejected commit.
            </p>
        </div>
    );

    if (!summary) {
        return (
            <div className="flex items-start gap-3">
                <Link
                    href={href}
                    className="flex min-w-0 flex-1 gap-2.5 rounded-md outline-offset-4 focus-visible:outline-2 focus-visible:outline-ring"
                >
                    <UserAvatar user={actor} className="size-7 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <div className="flex h-7 items-center gap-1.5 text-[15px]">
                            <span
                                className="truncate font-semibold"
                                title={actor.name}
                            >
                                {actor.name}
                            </span>
                            <span
                                className="hidden shrink-[4] truncate font-mono text-[13px] text-muted-foreground sm:inline"
                                title={`@${actor.username}`}
                            >
                                @{actor.username}
                            </span>
                            <span className="sr-only">
                                {isNew && 'New. '}
                                {action}
                            </span>
                            <span className="ml-auto flex shrink-0 items-center gap-2 pl-2">
                                {icon}
                                {time}
                            </span>
                        </div>
                        {notification.type !== 'starred' && excerpt}
                    </div>
                </Link>
                {notification.type === 'followed' && (
                    <FollowBackButton actor={actor} />
                )}
            </div>
        );
    }

    return (
        <div className="flex">
            <GraphRail
                lane={rail}
                previousLane={hasPrevious ? rail : undefined}
                node={
                    <span
                        className={cn(
                            'flex size-9 items-center justify-center rounded-full border-2 bg-card transition-colors',
                            isNew ? 'border-primary' : 'border-rail',
                        )}
                    >
                        {icon}
                    </span>
                }
            />
            <div className="min-w-0 flex-1 border-b py-3 pr-4">
                <div className="flex items-start gap-3">
                    <Link
                        href={href}
                        data-notification-link
                        className="min-w-0 flex-1 rounded-md outline-offset-4 focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        <div className="flex h-10 items-center gap-2">
                            {moderation ? (
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 font-mono text-xs',
                                        notification.type === 'rejected'
                                            ? 'bg-destructive/10 text-destructive'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {notification.type}
                                </span>
                            ) : (
                                <span className="flex -space-x-2" aria-hidden>
                                    {actors.map((stacked, position) => (
                                        <UserAvatar
                                            key={stacked.id}
                                            user={stacked}
                                            className="relative size-8 ring-2 ring-card"
                                            style={{
                                                zIndex:
                                                    actors.length - position,
                                            }}
                                        />
                                    ))}
                                    {actorsCount > actors.length && (
                                        <span className="relative flex h-8 min-w-8 items-center justify-center rounded-full bg-muted px-1.5 font-mono text-xs text-muted-foreground ring-2 ring-card">
                                            +{actorsCount - actors.length}
                                        </span>
                                    )}
                                </span>
                            )}
                            {time}
                        </div>
                        <p className="mt-1 text-[15px] wrap-anywhere">
                            {isNew && <span className="sr-only">New. </span>}
                            {moderation ? (
                                `${action}.`
                            ) : (
                                <>
                                    <span
                                        className="inline-block max-w-full truncate align-bottom font-semibold"
                                        title={actor.name}
                                    >
                                        {actor.name}
                                    </span>{' '}
                                    {otherActors > 0 ? (
                                        `and ${otherActors} ${otherActors === 1 ? 'other' : 'others'} `
                                    ) : (
                                        <>
                                            <span
                                                className="inline-block max-w-full truncate align-bottom font-mono text-[13px] text-muted-foreground"
                                                title={`@${actor.username}`}
                                            >
                                                @{actor.username}
                                            </span>{' '}
                                        </>
                                    )}
                                    {action}
                                </>
                            )}
                        </p>
                        {excerpt}
                        {reason}
                    </Link>
                    {notification.type === 'followed' && (
                        <span className="flex h-10 items-center">
                            <FollowBackButton actor={actor} />
                        </span>
                    )}
                </div>
                {children}
            </div>
        </div>
    );
}

Index.layout = AppLayout;
