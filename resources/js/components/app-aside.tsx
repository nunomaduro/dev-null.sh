import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import UserFollowersController from '@/actions/App/Http/Controllers/UserFollowersController';
import UserFollowingController from '@/actions/App/Http/Controllers/UserFollowingController';
import { GitHubIcon } from '@/components/github-icon';
import { LegalLinks, SignInAgreement } from '@/components/legal-links';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { WhoToFollow } from '@/components/who-to-follow';
import { useSharedState } from '@/hooks/use-shared-state';
import { commitStateKey } from '@/lib/commit-state';
import { useKeyLabels } from '@/lib/keyboard';
import { laneColor } from '@/lib/utils';
import { login } from '@/routes';
import { show } from '@/routes/user';

const rules = [
    'Everyone here signed in with GitHub.',
    'Commits are plain text, up to 4,000 characters.',
    'No algorithm. Newest first.',
    'Profiles mirror GitHub. Edit yours there.',
];

export function AppAside({
    onShowShortcuts,
    onSearch,
}: {
    onShowShortcuts: () => void;
    onSearch: () => void;
}) {
    const { user } = usePage().props.auth;
    const keys = useKeyLabels();
    const [commitsCount] = useSharedState(
        commitStateKey('commits_count', user?.id ?? ''),
        user?.commits_count ?? 0,
    );

    return (
        <aside className="sticky top-0 hidden h-svh w-80 shrink-0 [scrollbar-width:none] flex-col gap-8 overflow-y-auto px-6 py-6 xl:flex">
            <button
                type="button"
                onClick={onSearch}
                className="-mb-2 flex h-11 items-center gap-2.5 rounded-full border bg-card px-4 py-2.5 text-[15px] text-muted-foreground transition-colors hover:border-input hover:text-foreground"
                data-test="search-button"
            >
                <Search className="size-4" />
                Search
                <kbd className="ml-auto rounded border border-b-2 bg-muted px-1.5 font-mono text-xs">
                    {keys.combo(keys.mod, 'K')}
                </kbd>
            </button>

            {user === null ? (
                <section
                    aria-labelledby="aside-sign-in"
                    className="rounded-xl border bg-card p-4"
                >
                    <h2 id="aside-sign-in" className="font-semibold">
                        New to /dev/null?
                    </h2>
                    <p className="mt-1 text-sm text-pretty text-muted-foreground">
                        Sign in with GitHub to follow developers, star commits,
                        and join the conversation.
                    </p>
                    <Button className="mt-4 w-full" asChild>
                        <a href={login().url} data-test="aside-login-button">
                            <GitHubIcon className="size-4" />
                            Sign in with GitHub
                        </a>
                    </Button>
                    <SignInAgreement className="mt-3 text-xs" />
                </section>
            ) : (
                <section aria-labelledby="aside-you">
                    <h2 id="aside-you" className="sr-only">
                        You
                    </h2>
                    <Link
                        href={show(user)}
                        className="group flex items-center gap-3"
                    >
                        <UserAvatar user={user} className="size-12" />
                        <span className="min-w-0">
                            <span className="block truncate font-semibold group-hover:underline">
                                {user.name}
                            </span>
                            <span className="flex items-center gap-1.5 font-mono text-sm text-muted-foreground">
                                <span
                                    aria-hidden
                                    className="size-2 rounded-full"
                                    style={{
                                        background: laneColor(user.lane),
                                    }}
                                />
                                @{user.username}
                            </span>
                        </span>
                    </Link>
                    <dl className="mt-4 flex gap-4 text-sm">
                        {[
                            {
                                count: commitsCount,
                                label:
                                    commitsCount === 1 ? 'commit' : 'commits',
                                href: show(user),
                            },
                            {
                                count: user.following_count,
                                label: 'following',
                                href: UserFollowingController.index(user),
                            },
                            {
                                count: user.followers_count,
                                label:
                                    user.followers_count === 1
                                        ? 'follower'
                                        : 'followers',
                                href: UserFollowersController.index(user),
                            },
                        ].map((stat) => (
                            <div
                                key={stat.label}
                                className="group flex flex-row-reverse gap-1.5"
                            >
                                <dt className="text-muted-foreground">
                                    <Link
                                        href={stat.href}
                                        className="group-hover:underline"
                                    >
                                        {stat.label}
                                    </Link>
                                </dt>
                                <dd className="font-mono font-medium">
                                    <Link
                                        href={stat.href}
                                        className="group-hover:underline"
                                    >
                                        {stat.count}
                                    </Link>
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {user.bio && (
                        <p className="mt-3 text-sm text-pretty text-muted-foreground">
                            {user.bio}
                        </p>
                    )}
                </section>
            )}

            <WhoToFollow />

            <section aria-labelledby="aside-rules" className="border-t pt-6">
                <h2 id="aside-rules" className="mb-3 text-sm font-semibold">
                    How /dev/null works
                </h2>
                <ul className="space-y-2.5 text-sm">
                    {rules.map((rule) => (
                        <li key={rule} className="flex gap-2.5">
                            <span
                                aria-hidden
                                className="mt-[0.45em] size-1.5 shrink-0 rounded-full bg-rail"
                            />
                            {rule}
                        </li>
                    ))}
                </ul>
            </section>

            <div className="mt-auto space-y-2 text-sm text-muted-foreground">
                <button
                    type="button"
                    onClick={onShowShortcuts}
                    className="flex items-center gap-2 rounded-sm transition-colors hover:text-foreground"
                >
                    Keyboard shortcuts
                    <kbd className="min-w-5 rounded border border-b-2 bg-muted px-1 text-center font-mono text-xs">
                        ?
                    </kbd>
                </button>
                <LegalLinks />
                <p>Everything else goes to /dev/null.</p>
            </div>
        </aside>
    );
}
