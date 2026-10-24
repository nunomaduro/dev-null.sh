import type { VisitOptions } from '@inertiajs/core';
import { Link, router, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { UserCheck, UserPlus } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import UserFollowController from '@/actions/App/Http/Controllers/UserFollowController';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { refreshSharedState, useSharedState } from '@/hooks/use-shared-state';
import { signIn } from '@/lib/auth';
import { toastWhenThrottled } from '@/lib/throttle';
import { cn } from '@/lib/utils';
import type { Commit, CursorPaginated, User } from '@/types';

/**
 * A follow changes the Following log, but never the draw or loaded pages of another log.
 */
export function followVisitOptions(
    component: string,
    log: unknown,
    commits?: CursorPaginated<Commit>,
): Pick<VisitOptions, 'only' | 'headers'> {
    if (component !== 'log/show') {
        return { headers: { 'X-Follow-Request': 'true' } };
    }

    const through = commits?.data.at(-1)?.id;

    return log === 'following'
        ? {
              only: [
                  'auth.user',
                  'liveFollowing',
                  'commits',
                  'suggestions',
                  'newCommitsCount',
              ],
              // Link has no reset option; both controls must replace infinite-scroll results.
              headers: {
                  'X-Follow-Request': 'true',
                  'X-Inertia-Reset': 'commits',
                  ...(through && {
                      'X-Log-Through': through,
                  }),
              },
          }
        : {
              only: ['auth.user', 'liveFollowing'],
              headers: { 'X-Follow-Request': 'true' },
          };
}

/**
 * The pointer is still on the button right after a follow, so it keeps saying "Following" until the pointer leaves instead of flipping to a red "Unfollow".
 */
export function useFollowHover() {
    const [isJustToggled, setIsJustToggled] = useState(false);

    return {
        isJustToggled,
        markToggled: () => setIsJustToggled(true),
        onMouseLeave: () => setIsJustToggled(false),
    };
}

const isFollowingKey = (user: Pick<User, 'id'>): string =>
    `is_following:${user.id}`;

/**
 * Every follow control shares whether you follow someone, so a follow from a profile or a preview also shows in "Who to follow".
 */
export function useIsFollowing(
    user: Pick<User, 'id'>,
    isFollowing: boolean,
): [boolean, (isFollowing: boolean) => void] {
    return useSharedState(isFollowingKey(user), isFollowing);
}

const isPendingKey = (user: Pick<User, 'id'>): string =>
    `follow_pending:${user.id}`;

const hasBlockKey = (user: Pick<User, 'id'>): string => `has_block:${user.id}`;

/**
 * Whether this tab has seen a block between you, either way: one you made, or a follow the server refused.
 */
export function useHasBlockWith(user: Pick<User, 'id'>): boolean {
    const [hasBlock] = useSharedState(hasBlockKey(user), false);

    return hasBlock;
}

/**
 * A block works both ways and ends the follow.
 */
export function rememberBlock(user: Pick<User, 'id'>): void {
    refreshSharedState(
        new Map([
            [isFollowingKey(user), false],
            [hasBlockKey(user), true],
        ]),
    );
}

/**
 * The server only refuses a follow across a block, which a suggestion can't know about until it tries, so it toasts instead of swapping in the 403 page.
 */
export function onFollowHttpException(
    user: Pick<User, 'id' | 'username'>,
    response: { status: number },
): boolean {
    if (response.status !== 403) {
        return !toastWhenThrottled(response);
    }

    rememberBlock(user);
    toast.error(`You can't follow @${user.username}.`);

    return false;
}

export function FollowLabel({
    isFollowing,
    isJustToggled,
    followsYou = false,
}: {
    isFollowing: boolean;
    isJustToggled: boolean;
    followsYou?: boolean;
}) {
    if (!isFollowing) {
        return followsYou ? 'Follow back' : 'Follow';
    }

    if (isJustToggled) {
        return 'Following';
    }

    return (
        <>
            <span className="group-hover/follow:hidden">Following</span>
            <span className="hidden text-destructive group-hover/follow:inline">
                Unfollow
            </span>
        </>
    );
}

/**
 * Follows are async visits, so following several people in a row never cancels the follows still on their way. Each person's follow waits for the one before it to answer, so a double click can't follow and then unfollow.
 */
export function FollowButton({
    user,
    isFollowing: initialIsFollowing,
    followsYou = false,
    size,
    className,
    onClick,
    onSuccess,
    ...props
}: {
    user: Pick<User, 'id' | 'username'>;
    isFollowing: boolean;
    followsYou?: boolean;
    size?: ComponentProps<typeof Button>['size'];
    className?: string;
} & Omit<InertiaLinkProps, 'href' | 'as' | 'method' | 'size' | 'className'>) {
    const {
        component,
        props: { auth, log, commits },
    } = usePage<{ commits?: CursorPaginated<Commit> }>();
    const [isFollowing, setIsFollowing] = useIsFollowing(
        user,
        initialIsFollowing,
    );
    const [isPending, setIsPending] = useSharedState<boolean>(
        isPendingKey(user),
        false,
    );
    const { isJustToggled, markToggled, onMouseLeave } = useFollowHover();

    if (auth.user === null) {
        return (
            <Button
                size={size}
                className={className}
                onClick={signIn}
                aria-label={`Follow @${user.username}`}
                data-test="follow-button"
            >
                Follow
            </Button>
        );
    }

    return (
        <Button
            variant={isFollowing ? 'outline' : 'default'}
            size={size}
            className={cn('group/follow', className)}
            asChild
        >
            <Link
                href={
                    isFollowing
                        ? UserFollowController.destroy(user)
                        : UserFollowController.store(user)
                }
                as="button"
                preserveScroll
                async
                onBefore={() => !isPending}
                onStart={() => setIsPending(true)}
                onFinish={() => setIsPending(false)}
                disabled={isPending}
                onHttpException={(response) =>
                    onFollowHttpException(user, response)
                }
                aria-label={
                    isFollowing
                        ? `Unfollow @${user.username}`
                        : `${followsYou ? 'Follow back' : 'Follow'} @${user.username}`
                }
                data-test="follow-button"
                onClick={(event) => {
                    markToggled();
                    onClick?.(event);
                }}
                onSuccess={(page) => {
                    setIsFollowing(!isFollowing);
                    router.flushAll();
                    onSuccess?.(page);
                }}
                onMouseLeave={onMouseLeave}
                {...props}
                {...followVisitOptions(component, log, commits)}
            >
                <FollowLabel
                    isFollowing={isFollowing}
                    isJustToggled={isJustToggled}
                    followsYou={followsYou}
                />
            </Link>
        </Button>
    );
}

/**
 * An icon rather than a full button, for lists that repeat it on every row, like an expanded group of followers or
 * who to follow.
 */
export function FollowIconButton({
    user,
    isFollowing: initialIsFollowing,
    followsYou = false,
    marksFollowsYou = false,
    onFollow,
    ...props
}: {
    user: Pick<User, 'id' | 'username'>;
    isFollowing: boolean;
    followsYou?: boolean;
    marksFollowsYou?: boolean;
    onFollow?: () => void;
    'data-test'?: string;
}) {
    const {
        component,
        props: { log, commits },
    } = usePage<{ commits?: CursorPaginated<Commit> }>();
    const [isFollowing, setIsFollowing] = useIsFollowing(
        user,
        initialIsFollowing,
    );
    const Icon = isFollowing ? UserCheck : UserPlus;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href={
                        isFollowing
                            ? UserFollowController.destroy(user)
                            : UserFollowController.store(user)
                    }
                    as="button"
                    only={['auth']}
                    preserveState
                    preserveScroll
                    async
                    onHttpException={(response) =>
                        onFollowHttpException(user, response)
                    }
                    onSuccess={() => {
                        setIsFollowing(!isFollowing);
                        router.flushAll();

                        if (!isFollowing) {
                            onFollow?.();
                        }
                    }}
                    aria-label={
                        isFollowing
                            ? `Unfollow @${user.username}`
                            : `Follow @${user.username}${followsYou ? ' back' : ''}`
                    }
                    className={cn(
                        'shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        {
                            'text-primary hover:text-primary':
                                isFollowing || (marksFollowsYou && followsYou),
                        },
                    )}
                    {...props}
                    {...followVisitOptions(component, log, commits)}
                >
                    <Icon aria-hidden className="size-4" />
                </Link>
            </TooltipTrigger>
            <TooltipContent>
                {isFollowing
                    ? `Following @${user.username}`
                    : followsYou
                      ? `Follow @${user.username} back`
                      : `Follow @${user.username}`}
            </TooltipContent>
        </Tooltip>
    );
}
