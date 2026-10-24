import { Deferred, Link, router, usePage } from '@inertiajs/react';
import { Ellipsis, EyeOff, RefreshCw, X } from 'lucide-react';
import { useRef, useState } from 'react';
import SuggestionDismissalController from '@/actions/App/Http/Controllers/SuggestionDismissalController';
import {
    FollowButton,
    FollowIconButton,
    useHasBlockWith,
} from '@/components/follow-button';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { UserPreview } from '@/components/user-preview';
import { useSharedState } from '@/hooks/use-shared-state';
import { currentRound, useWhoToFollow } from '@/hooks/use-who-to-follow';
import { formatCount, formatExactCount } from '@/lib/numbers';
import { toastWhenThrottled } from '@/lib/throttle';
import { cn, laneColor } from '@/lib/utils';
import { edit } from '@/routes/appearance';
import { show } from '@/routes/user';
import type { SuggestedUser } from '@/types';

function reasonFor(user: SuggestedUser, format = formatCount): string | null {
    if (user.followed_by_following_count > 0) {
        return `Followed by ${format(user.followed_by_following_count)} you follow`;
    }

    if (user.followers_count > 0) {
        return user.followers_count === 1
            ? '1 follower'
            : `${format(user.followers_count)} followers`;
    }

    return null;
}

function BioComment({ bio }: { bio: string }) {
    return (
        <span
            className="block truncate font-mono text-xs text-muted-foreground"
            title={bio}
        >
            <span aria-hidden className="text-muted-foreground/60">
                #{' '}
            </span>
            {bio}
        </span>
    );
}

function Reason({ reason, title }: { reason: string; title: string }) {
    return (
        <span
            className="block truncate text-xs text-muted-foreground"
            title={title}
        >
            {reason}
        </span>
    );
}

const shownSuggestionsCount = 3;

// Long enough to see the follow land before the row makes way for someone else.
const followedRowDelay = 1000;

function DismissButton({
    user,
    onDismiss,
}: {
    user: SuggestedUser;
    onDismiss: () => void;
}) {
    const label = `Not interested. @${user.username} won't be suggested again.`;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={label}
                    className="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    data-test="suggested-dismiss-button"
                >
                    <X aria-hidden className="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent>
                Not interested
                <span className="block opacity-70">
                    @{user.username} won't be suggested again.
                </span>
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * The aside is too narrow to share a line with the button, so there the bio and reason go below on their own lines.
 *
 * With onDismiss, the row gets small follow and dismiss icons instead of the full follow button. onDismiss returns
 * whether someone takes the row's place; otherwise the row folds away. A failed dismissal opens it back up. onFollow
 * does the same a moment after a follow from the row, since someone you follow is no longer a suggestion. These rows
 * keep room for both lines below the name, so someone without a bio taking a slot doesn't move the rows under it.
 */
export function SuggestedUserRow({
    user,
    isStacked = false,
    className,
    buttonClassName,
    onDismiss,
    onFollow,
}: {
    user: SuggestedUser;
    isStacked?: boolean;
    className?: string;
    buttonClassName?: string;
    onDismiss?: (onFailed: () => void) => boolean;
    onFollow?: () => boolean;
}) {
    const reason = reasonFor(user);
    const exactReason = reasonFor(user, formatExactCount);
    const bio = user.bio?.trim() || null;
    const details = (bio || reason) && (
        <>
            {bio && <BioComment bio={bio} />}
            {reason && <Reason reason={reason} title={exactReason ?? reason} />}
        </>
    );
    const avatarRef = useRef<HTMLSpanElement>(null);
    const hasBlock = useHasBlockWith(user);
    const [isDismissed, setIsDismissed] = useState(false);
    const [isGone, setIsGone] = useState(false);
    const isLeaving = hasBlock || isDismissed;

    if (isGone) {
        return null;
    }

    return (
        <li
            inert={isLeaving}
            className={cn(
                'grid grid-cols-1 transition-[grid-template-rows,opacity,margin] duration-300 ease-out',
                isLeaving
                    ? 'm-0 grid-rows-[0fr] border-0 opacity-0'
                    : 'grid-rows-[1fr]',
            )}
            onTransitionEnd={(event) => {
                if (
                    isLeaving &&
                    event.target === event.currentTarget &&
                    event.propertyName === 'grid-template-rows'
                ) {
                    setIsGone(true);
                }
            }}
        >
            <div className={cn('min-h-0', { 'overflow-hidden': isLeaving })}>
                <div className={cn('flex items-center gap-3', className)}>
                    <UserPreview
                        user={user}
                        anchor={avatarRef}
                        className="flex min-w-0 flex-1"
                    >
                        <Link
                            href={show(user)}
                            className="group flex min-w-0 flex-1 items-center gap-3"
                        >
                            <span
                                ref={avatarRef}
                                className="inline-flex shrink-0"
                            >
                                <UserAvatar user={user} className="size-9" />
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-semibold group-hover:underline">
                                    {user.name}
                                </span>
                                <span className="flex min-w-0 items-center gap-1.5 font-mono text-xs text-muted-foreground">
                                    <span
                                        aria-hidden
                                        className="size-1.5 shrink-0 rounded-full"
                                        style={{
                                            background: laneColor(user.lane),
                                        }}
                                    />
                                    <span className="truncate">
                                        @{user.username}
                                    </span>
                                </span>
                                {!isStacked && (
                                    <span
                                        className={cn('block', {
                                            'min-h-8': onDismiss,
                                        })}
                                    >
                                        {details}
                                    </span>
                                )}
                            </span>
                        </Link>
                    </UserPreview>
                    {onDismiss ? (
                        <div className="flex shrink-0 items-center gap-0.5">
                            <FollowIconButton
                                user={user}
                                isFollowing={false}
                                followsYou={user.follows_you}
                                marksFollowsYou
                                onFollow={() =>
                                    window.setTimeout(
                                        () =>
                                            onFollow?.() ||
                                            setIsDismissed(true),
                                        followedRowDelay,
                                    )
                                }
                                data-test="suggested-follow-button"
                            />
                            <DismissButton
                                user={user}
                                onDismiss={() =>
                                    onDismiss(() => setIsDismissed(false)) ||
                                    setIsDismissed(true)
                                }
                            />
                        </div>
                    ) : (
                        <FollowButton
                            user={user}
                            isFollowing={false}
                            followsYou={user.follows_you}
                            size="sm"
                            className={cn(
                                'min-w-24 shrink-0',
                                buttonClassName,
                                user.follows_you && 'min-w-26',
                            )}
                            only={['auth']}
                            preserveState
                            onSuccess={() => router.flushAll()}
                            data-test="suggested-follow-button"
                        />
                    )}
                </div>
                {isStacked && (
                    <div className="mt-1.5 min-h-8 pl-12">{details}</div>
                )}
            </div>
        </li>
    );
}

function SuggestedUsersSkeleton({
    rowClassName,
    isStacked = false,
}: {
    rowClassName?: string;
    isStacked?: boolean;
}) {
    const details = (
        <>
            <Skeleton className="my-0.5 h-3 w-36" />
            <Skeleton className="my-0.5 h-3 w-32" />
        </>
    );

    return (
        <>
            {[0, 1, 2].map((index) => (
                <li key={index} aria-hidden className={rowClassName}>
                    <div className="flex items-center gap-3">
                        <Skeleton className="size-9 shrink-0 rounded-full" />
                        <span className="flex-1">
                            <Skeleton className="my-[3px] h-3.5 w-28" />
                            <Skeleton className="my-0.5 h-3 w-20" />
                            {!isStacked && details}
                        </span>
                        <Skeleton className="h-7 w-[58px] shrink-0" />
                    </div>
                    {isStacked && (
                        <div className="mt-1.5 min-h-8 pl-12">{details}</div>
                    )}
                </li>
            ))}
        </>
    );
}

function WhoToFollowRefreshButton({ disabled }: { disabled: boolean }) {
    const { isRefreshing, refreshSuggestedUsers } = useWhoToFollow();

    return (
        <Button
            variant="ghost"
            size="icon"
            className="size-7 text-muted-foreground"
            onClick={refreshSuggestedUsers}
            disabled={disabled}
            aria-disabled={isRefreshing}
            aria-label="Suggest other people"
            title="Suggest other people"
            data-test="who-to-follow-refresh"
        >
            <RefreshCw
                className={cn(
                    isRefreshing && 'animate-spin motion-reduce:animate-none',
                )}
            />
        </Button>
    );
}

function WhoToFollowActions({
    hasSuggestions,
    onHide,
}: {
    hasSuggestions: boolean;
    onHide: () => void;
}) {
    return (
        <div className="flex items-center gap-1">
            <WhoToFollowRefreshButton disabled={!hasSuggestions} />
            <WhoToFollowMenu onHide={onHide} />
        </div>
    );
}

function WhoToFollowMenu({ onHide }: { onHide: () => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7 text-muted-foreground"
                    aria-label="Who to follow options"
                    data-test="who-to-follow-menu"
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem onSelect={onHide}>
                    <EyeOff />
                    <span>
                        Don't show again
                        <span className="block font-mono text-xs text-muted-foreground">
                            &gt; /dev/null
                        </span>
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Hiding only writes the cookie, so the block stays put with an undo until the next visit leaves it out.
 */
function useHideWhoToFollow() {
    const { showsWhoToFollow, suggestedUsers } = usePage().props;
    const { rememberShowsWhoToFollow } = useWhoToFollow();
    const [isHidden, setIsHidden] = useState(false);
    const { hasDismissed, isEmptied, dismiss, follow } = useDismissSuggestion();

    const toggle = (hides: boolean) => {
        rememberShowsWhoToFollow(!hides);
        setIsHidden(hides);
    };

    return {
        isVisible:
            showsWhoToFollow && (suggestedUsers?.length !== 0 || hasDismissed),
        isHidden,
        isEmptied,
        hide: () => toggle(true),
        undo: () => toggle(false),
        suggestedUsers: suggestedUsers?.slice(0, shownSuggestionsCount),
        dismiss,
        follow,
    };
}

type TopUp = 'idle' | 'running' | 'queued';

const dismissedIds = new Set<SuggestedUser['id']>();
const followedIds = new Set<SuggestedUser['id']>();

/**
 * The server sends a few spare suggestions after the shown ones, so a dismissed person's slot is taken by the next
 * spare right away while everyone else stays put. Someone followed from a row makes way the same way, with nothing
 * left to save. Once every dismissal is through, the spares are topped up from the server, which only adds people
 * after the ones already here. A dismissal that fails puts the person back in their slot, unless a refresh has moved
 * on to other people since.
 *
 * A top-up can be answered before someone else's dismissal is saved, so everyone dismissed since the page loaded is
 * taken out again when it lands, and the next top-up follows. Someone followed is only taken out of top-ups sent
 * before their follow: the server leaves them out of later ones while you follow them, and suggests them again once
 * you don't. Visiting another page cancels a top-up, so it is sent again once that page is shown.
 *
 * The aside and the log share this state, so both say nobody is left once a top-up finds nobody else, until the next
 * page load leaves the block out like any other time there's nobody to suggest.
 */
function useDismissSuggestion() {
    const { suggestedUsers = [] } = usePage().props;
    const [dismissingCount, setDismissingCount] = useSharedState<number>(
        'who-to-follow-dismissing',
        0,
    );
    const [topUp, setTopUp] = useSharedState<TopUp>(
        'who-to-follow-top-up',
        'idle',
    );
    const [hasDismissed, setHasDismissed] = useSharedState<boolean>(
        'who-to-follow-dismissed',
        false,
    );

    const remove = (user: Pick<SuggestedUser, 'id'>): void => {
        // A page visited in the meantime may have no suggestions at all.
        router.replaceProp(
            'suggestedUsers',
            (current: SuggestedUser[] | undefined) => {
                if (current === undefined) {
                    return current;
                }

                const slot = current.findIndex(
                    (suggested) => suggested.id === user.id,
                );

                if (slot === -1) {
                    return current;
                }

                const remaining = [...current];
                const spare =
                    slot < shownSuggestionsCount
                        ? remaining.splice(shownSuggestionsCount, 1)
                        : [];
                remaining.splice(slot, 1, ...spare);

                return remaining;
            },
        );
    };

    /**
     * Whoever holds the slot now came from the spares, unless they were already shown, so they go back to the front.
     */
    const restore = (
        user: SuggestedUser,
        slot: number,
        shownIds: SuggestedUser['id'][],
    ): void => {
        router.replaceProp('suggestedUsers', (current: SuggestedUser[]) => {
            if (current.some((suggested) => suggested.id === user.id)) {
                return current;
            }

            const restored = [...current];
            const holder = restored.at(slot);

            if (holder && !shownIds.includes(holder.id)) {
                restored.splice(slot, 1, user);
                restored.splice(shownSuggestionsCount, 0, holder);
            } else {
                restored.splice(slot, 0, user);
            }

            return restored;
        });
    };

    const sendTopUp = (): void => {
        const followedBefore = [...followedIds];
        let isApplied = false;

        setTopUp('running');
        router.reload({
            only: ['suggestedUsers'],
            async: true,
            onBeforeUpdate: () => {
                isApplied = true;
            },
            onSuccess: () => {
                [...dismissedIds, ...followedIds].forEach((id) =>
                    remove({ id }),
                );
                followedBefore.forEach((id) => followedIds.delete(id));
            },
            onFinish: () => {
                if (!isApplied) {
                    const stop = router.on('navigate', () => {
                        stop();
                        sendTopUp();
                    });

                    return;
                }

                // The shared setter runs its update at once, so what it saw can be read right after.
                let wasQueued = false;
                setTopUp((current) => {
                    wasQueued = current === 'queued';

                    return 'idle';
                });

                if (wasQueued) {
                    sendTopUp();
                }
            },
        });
    };

    const settle = (): void => {
        let left = 0;
        setDismissingCount((count) => (left = count - 1));

        if (left > 0) {
            return;
        }

        let isRunning = false;
        setTopUp((current) => {
            isRunning = current !== 'idle';

            return isRunning ? 'queued' : current;
        });

        if (!isRunning) {
            sendTopUp();
        }
    };

    /**
     * Returns whether a spare takes the person's slot.
     */
    const takeOut = (
        user: SuggestedUser,
        takenOutIds: Set<SuggestedUser['id']>,
    ): boolean => {
        const hasSpare = suggestedUsers.length > shownSuggestionsCount;

        takenOutIds.add(user.id);
        setHasDismissed(true);

        if (hasSpare) {
            remove(user);
        } else {
            // Taken out after the row has folded away, even if the block it was dismissed from is no longer shown.
            window.setTimeout(() => {
                if (takenOutIds.has(user.id)) {
                    remove(user);
                }
            }, 300);
        }

        return hasSpare;
    };

    const follow = (user: SuggestedUser): boolean => {
        setDismissingCount((count) => count + 1);
        const hasSpare = takeOut(user, followedIds);
        settle();

        return hasSpare;
    };

    const dismiss = (user: SuggestedUser, onFailed: () => void): boolean => {
        const shownIds = suggestedUsers
            .slice(0, shownSuggestionsCount)
            .map((suggested) => suggested.id);
        const slot = shownIds.indexOf(user.id);
        const round = currentRound();
        let isSaved = false;

        setDismissingCount((count) => count + 1);
        router.visit(SuggestionDismissalController.store(user), {
            only: ['auth'],
            preserveState: true,
            preserveScroll: true,
            async: true,
            onHttpException: (response) => !toastWhenThrottled(response),
            onSuccess: () => {
                isSaved = true;
                router.flushAll();
            },
            onFinish: () => {
                if (!isSaved) {
                    dismissedIds.delete(user.id);
                    onFailed();

                    if (currentRound() === round) {
                        restore(user, slot, shownIds);
                    }
                }

                settle();
            },
        });

        return takeOut(user, dismissedIds);
    };

    return {
        hasDismissed,
        isEmptied:
            hasDismissed &&
            dismissingCount === 0 &&
            topUp === 'idle' &&
            suggestedUsers.length === 0,
        dismiss,
        follow,
    };
}

function WhoToFollowEmptied() {
    return (
        <div data-test="who-to-follow-emptied">
            <p className="font-mono text-[13px] text-muted-foreground">
                fatal: no one left to suggest
            </p>
            <p className="mt-1 text-xs text-pretty text-muted-foreground">
                You've followed or dismissed everyone we'd suggest. New people
                show up here as they start committing.
            </p>
        </div>
    );
}

function WhoToFollowHidden({ onUndo }: { onUndo: () => void }) {
    return (
        <div>
            <p className="font-mono text-[13px] text-muted-foreground">
                $ who --should-i-follow{' '}
                <span className="whitespace-nowrap text-foreground">
                    &gt; /dev/null
                </span>
            </p>
            <div className="mt-1 flex items-center justify-between gap-3">
                <p className="min-w-0 text-xs text-muted-foreground">
                    Bring it back in{' '}
                    <Link href={edit()} className="underline">
                        Settings
                    </Link>
                    .
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    onClick={onUndo}
                    data-test="who-to-follow-undo"
                >
                    Undo
                </Button>
            </div>
        </div>
    );
}

export function WhoToFollow() {
    const {
        isVisible,
        isHidden,
        isEmptied,
        hide,
        undo,
        suggestedUsers,
        dismiss,
        follow,
    } = useHideWhoToFollow();

    if (!isVisible) {
        return null;
    }

    return (
        <section
            aria-labelledby="aside-who-to-follow"
            className="-mt-4 border-t pt-4"
        >
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 id="aside-who-to-follow" className="text-sm font-semibold">
                    Who to follow
                </h2>
                {!isHidden && !isEmptied && (
                    <WhoToFollowActions
                        hasSuggestions={suggestedUsers !== undefined}
                        onHide={hide}
                    />
                )}
            </div>
            {isHidden ? (
                <WhoToFollowHidden onUndo={undo} />
            ) : isEmptied ? (
                <WhoToFollowEmptied />
            ) : (
                <ul className="space-y-4">
                    <Deferred
                        data="suggestedUsers"
                        fallback={<SuggestedUsersSkeleton isStacked />}
                    >
                        {suggestedUsers?.map((user) => (
                            <SuggestedUserRow
                                key={user.id}
                                user={user}
                                isStacked
                                onDismiss={(onFailed) =>
                                    dismiss(user, onFailed)
                                }
                                onFollow={() => follow(user)}
                            />
                        ))}
                    </Deferred>
                </ul>
            )}
        </section>
    );
}

/**
 * The aside is hidden below the xl breakpoint, so smaller screens get the same suggestions inside the log.
 */
export function WhoToFollowInLog() {
    const {
        isVisible,
        isHidden,
        isEmptied,
        hide,
        undo,
        suggestedUsers,
        dismiss,
        follow,
    } = useHideWhoToFollow();

    if (!isVisible) {
        return null;
    }

    return (
        <section aria-labelledby="log-who-to-follow" className="flex xl:hidden">
            <div aria-hidden className="w-8 shrink-0 sm:w-9" />
            <div className="min-w-0 flex-1 border-b py-4 pr-4">
                <div
                    className={cn(
                        'mb-2 flex items-center justify-between gap-2',
                        { 'sr-only': isHidden },
                    )}
                >
                    <h2
                        id="log-who-to-follow"
                        className="font-mono text-[13px] text-muted-foreground"
                    >
                        $ who --should-i-follow
                    </h2>
                    {!isHidden && !isEmptied && (
                        <WhoToFollowActions
                            hasSuggestions={suggestedUsers !== undefined}
                            onHide={hide}
                        />
                    )}
                </div>
                {isHidden ? (
                    <WhoToFollowHidden onUndo={undo} />
                ) : isEmptied ? (
                    <WhoToFollowEmptied />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        <Deferred
                            data="suggestedUsers"
                            fallback={
                                <SuggestedUsersSkeleton rowClassName="p-3" />
                            }
                        >
                            {suggestedUsers?.map((user) => (
                                <SuggestedUserRow
                                    key={user.id}
                                    user={user}
                                    className="p-3"
                                    onDismiss={(onFailed) =>
                                        dismiss(user, onFailed)
                                    }
                                    onFollow={() => follow(user)}
                                />
                            ))}
                        </Deferred>
                    </ul>
                )}
            </div>
        </section>
    );
}
