import { useEffect, useState } from 'react';
import CommitGitHubActivityController from '@/actions/App/Http/Controllers/CommitGitHubActivityController';
import { ActivityGraph } from '@/components/activity-graph';
import { emptyYear } from '@/lib/github-activity';
import { cn, laneColor } from '@/lib/utils';
import type { ActivityBucket, Commit } from '@/types';

type GitHubActivity = {
    login: string;
    days: ActivityBucket[];
};

type State =
    | { status: 'loading' }
    | { status: 'loaded'; activity: GitHubActivity }
    | { status: 'unavailable' };

async function fetchActivity(commitId: string): Promise<GitHubActivity | null> {
    const response = await fetch(
        CommitGitHubActivityController.show(commitId).url,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    );

    if (!response.ok) {
        throw new Error('Unable to load the GitHub activity');
    }

    return ((await response.json()) as { activity: GitHubActivity | null })
        .activity;
}

/**
 * An /activity commit shows its author's year on GitHub, drawn like their devnull commit activity, in their lane.
 */
export function GitHubActivityGraph({ commit }: { commit: Commit }) {
    const [state, setState] = useState<State>({ status: 'loading' });
    const [isSkeletonVisible, setIsSkeletonVisible] = useState(false);
    const [placeholder] = useState(() => emptyYear(new Date()));

    const commitId = commit.id;

    useEffect(() => {
        let isCancelled = false;

        fetchActivity(commitId)
            .then((activity) => {
                if (!isCancelled) {
                    setState(
                        activity === null
                            ? { status: 'unavailable' }
                            : { status: 'loaded', activity },
                    );
                }
            })
            .catch(() => {
                if (!isCancelled) {
                    setState({ status: 'unavailable' });
                }
            });

        // Fast answers show nothing in between...
        const skeleton = window.setTimeout(
            () => setIsSkeletonVisible(true),
            300,
        );

        return () => {
            isCancelled = true;
            window.clearTimeout(skeleton);
        };
    }, [commitId]);

    const login =
        state.status === 'loaded' ? state.activity.login : commit.user.username;

    return (
        <div
            aria-busy={state.status === 'loading'}
            className={cn('transition-opacity duration-300', {
                'opacity-0': state.status === 'loading' && !isSkeletonVisible,
                'animate-pulse':
                    state.status === 'loading' && isSkeletonVisible,
            })}
            data-test="github-activity"
            data-state={state.status}
        >
            <ActivityGraph
                activity={{
                    scale: 'year',
                    buckets:
                        state.status === 'loaded'
                            ? state.activity.days
                            : placeholder,
                }}
                lane={laneColor(commit.user.lane)}
                noun={['contribution', 'contributions']}
                fitWidth
                className="mt-3 rounded-lg border px-3 py-3 sm:px-4"
                heading={
                    <>
                        GitHub activity{' '}
                        <a
                            href={`https://github.com/${login}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-mono text-[13px] font-normal text-muted-foreground hover:text-foreground hover:underline"
                        >
                            @{login}
                        </a>
                    </>
                }
                summary={
                    state.status === 'loaded'
                        ? undefined
                        : state.status === 'loading'
                          ? 'Loading…'
                          : 'Not available right now'
                }
            />
        </div>
    );
}
