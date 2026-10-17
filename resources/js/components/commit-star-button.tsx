import { router, useHttp, usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import type { Ref } from 'react';
import CommitStarController from '@/actions/App/Http/Controllers/CommitStarController';
import { CommitStarPollingContext } from '@/components/commit-star-polling';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { refreshSharedState, useSharedState } from '@/hooks/use-shared-state';
import { signIn } from '@/lib/auth';
import { createCommitStarMutations } from '@/lib/commit-star-mutations';
import { commitStateKey } from '@/lib/commit-state';
import { toastWhenThrottled } from '@/lib/throttle';
import { cn } from '@/lib/utils';
import type { Commit } from '@/types';

function starLabel(starred: boolean, count: number): string {
    const verb = starred ? 'Unstar' : 'Star';

    if (count === 0) {
        return `${verb} commit`;
    }

    const noun = count === 1 ? 'star' : 'stars';

    return `${verb} commit, ${count} ${noun}`;
}

export function CommitStarButton({
    commit,
    className,
}: {
    commit: Commit;
    className?: string;
}) {
    const { auth } = usePage().props;
    const isOwn = commit.user_id === auth.user?.id;
    const [starred, setStarred] = useSharedState(
        commitStateKey('starred', commit.id),
        commit.starred,
    );
    const [count, setCount] = useSharedState(
        commitStateKey('stars_count', commit.id),
        commit.stars_count,
    );
    const request = useHttp<
        Record<string, never>,
        { starred: boolean; stars_count: number }
    >({});
    const [isAnimating, setIsAnimating] = useState(false);
    const [localMutations] = useState(createCommitStarMutations);
    const polling = useContext(CommitStarPollingContext);
    const mutations = polling?.mutations ?? localMutations;
    const canPoll =
        (isOwn || commit.status === 'approved') && auth.user !== null;
    const element = useRef<HTMLElement>(null);
    const setElement = useCallback((node: HTMLElement | null) => {
        element.current = node;
    }, []);

    useEffect(() => {
        if (!polling || !canPoll || !element.current) {
            return;
        }

        return polling.subscribe({
            id: commit.id,
            element: element.current,
            prepare: () =>
                mutations.prepare(commit.id, (stars) => {
                    refreshSharedState(
                        new Map<string, boolean | number>([
                            [
                                commitStateKey('starred', commit.id),
                                stars.starred,
                            ],
                            [
                                commitStateKey('stars_count', commit.id),
                                stars.stars_count,
                            ],
                        ]),
                    );
                }),
        });
    }, [polling, mutations, canPoll, commit.id, isOwn]);

    if (isOwn) {
        return (
            <StarCount ref={setElement} count={count} className={className} />
        );
    }

    if (commit.status !== 'approved') {
        return null;
    }

    const toggle = async () => {
        if (auth.user === null) {
            signIn();

            return;
        }

        if (request.processing) {
            return;
        }

        const finishMutation = mutations.begin(commit.id);

        if (!finishMutation) {
            return;
        }

        const next = !starred;

        setStarred(next);
        setCount((current) => Math.max(0, current + (next ? 1 : -1)));
        if (next) {
            setIsAnimating(true);
        }

        try {
            const response = await request.submit(
                next
                    ? CommitStarController.store(commit)
                    : CommitStarController.destroy(commit),
                {
                    onHttpException: (response) => {
                        toastWhenThrottled(response);
                    },
                },
            );

            setStarred(response.starred);
            setCount(response.stars_count);
            router.flushAll();
        } catch {
            setStarred(!next);
            setCount((current) => Math.max(0, current + (next ? -1 : 1)));
        } finally {
            finishMutation();
        }
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    ref={setElement}
                    aria-disabled={request.processing}
                    type="button"
                    onClick={toggle}
                    aria-pressed={starred}
                    aria-label={starLabel(starred, count)}
                    className={cn(
                        'group/star inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-mono text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                        starred && 'text-primary hover:text-primary',
                        className,
                    )}
                    data-test="star-commit-button"
                >
                    <Star
                        onAnimationEnd={() => setIsAnimating(false)}
                        className={cn(
                            'size-4 transition-transform group-hover/star:scale-110 active:scale-95',
                            starred && 'fill-current',
                            isAnimating && 'motion-safe:animate-star-pop',
                        )}
                    />
                    <span
                        data-test="star-count"
                        className="min-w-[1ch] text-left"
                    >
                        {count > 0 ? count : ''}
                    </span>
                </button>
            </TooltipTrigger>
            <TooltipContent>{starred ? 'Unstar' : 'Star'}</TooltipContent>
        </Tooltip>
    );
}

/**
 * Authors can't star their own commits, so they only see how many stars others gave.
 */
function StarCount({
    ref,
    count,
    className,
}: {
    ref?: Ref<HTMLSpanElement>;
    count: number;
    className?: string;
}) {
    const label =
        count === 0
            ? 'No stars yet'
            : `${count} ${count === 1 ? 'star' : 'stars'}`;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    ref={ref}
                    role="img"
                    aria-label={label}
                    className={cn(
                        'inline-flex cursor-default items-center gap-1 rounded-md px-1.5 py-1 font-mono text-[13px] text-muted-foreground',
                        count === 0 && 'text-muted-foreground/50',
                        className,
                    )}
                    data-test="star-count-readonly"
                >
                    <Star aria-hidden className="size-4" />
                    <span
                        data-test="star-count"
                        className="min-w-[1ch] text-left"
                    >
                        {count > 0 ? count : ''}
                    </span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
