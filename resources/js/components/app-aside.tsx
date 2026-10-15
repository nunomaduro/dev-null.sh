import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import UserFollowersController from '@/actions/App/Http/Controllers/UserFollowersController';
import UserFollowingController from '@/actions/App/Http/Controllers/UserFollowingController';
import { UserAvatar } from '@/components/user-avatar';
import { WhoToFollow } from '@/components/who-to-follow';
import { laneColor } from '@/lib/utils';
import { show } from '@/routes/user';

const rules = [
    'Everyone here signed in with GitHub.',
    'Commits are plain text, 280 characters max.',
    'No likes, no reposts, no algorithm. Newest first.',
    'Profiles mirror GitHub. Edit yours there.',
];

export function AppAside({
    onShowShortcuts,
    onSearch,
}: {
    onShowShortcuts: () => void;
    onSearch: () => void;
}) {
    const { auth, onlineDevelopersCount } = usePage().props;

    return (
        <aside className="sticky top-0 hidden h-svh w-80 shrink-0 flex-col gap-8 px-6 py-6 xl:flex">
            <button
                type="button"
                onClick={onSearch}
                className="-mb-2 flex h-11 items-center gap-2.5 rounded-full border bg-card px-4 py-2.5 text-[15px] text-muted-foreground transition-colors hover:border-input hover:text-foreground"
                data-test="search-button"
            >
                <Search className="size-4" />
                Search developers
                <kbd className="ml-auto rounded border border-b-2 bg-muted px-1.5 font-mono text-xs">
                    ⌘K
                </kbd>
            </button>

            <section aria-labelledby="aside-you">
                <h2 id="aside-you" className="sr-only">
                    You
                </h2>
                <Link
                    href={show(auth.user)}
                    className="group flex items-center gap-3"
                >
                    <UserAvatar user={auth.user} className="size-12" />
                    <span className="min-w-0">
                        <span className="block truncate font-semibold group-hover:underline">
                            {auth.user.name}
                        </span>
                        <span className="flex items-center gap-1.5 font-mono text-sm text-muted-foreground">
                            <span
                                aria-hidden
                                className="size-2 rounded-full"
                                style={{
                                    background: laneColor(auth.user.lane),
                                }}
                            />
                            @{auth.user.username}
                        </span>
                    </span>
                </Link>
                <dl className="mt-4 flex gap-4 text-sm">
                    {[
                        {
                            count: auth.user.commits_count,
                            label:
                                auth.user.commits_count === 1
                                    ? 'commit'
                                    : 'commits',
                            href: show(auth.user),
                        },
                        {
                            count: auth.user.following_count,
                            label: 'following',
                            href: UserFollowingController.index(auth.user),
                        },
                        {
                            count: auth.user.followers_count,
                            label:
                                auth.user.followers_count === 1
                                    ? 'follower'
                                    : 'followers',
                            href: UserFollowersController.index(auth.user),
                        },
                    ].map((stat) => (
                        <div
                            key={stat.label}
                            className="flex flex-row-reverse gap-1.5"
                        >
                            <dt className="text-muted-foreground">
                                <Link
                                    href={stat.href}
                                    className="hover:underline"
                                >
                                    {stat.label}
                                </Link>
                            </dt>
                            <dd className="font-mono font-medium">
                                {stat.count}
                            </dd>
                        </div>
                    ))}
                </dl>
                {auth.user.bio && (
                    <p className="mt-3 text-sm text-pretty text-muted-foreground">
                        {auth.user.bio}
                    </p>
                )}
            </section>

            <WhoToFollow />

            <section aria-labelledby="aside-rules" className="border-t pt-6">
                <h2 id="aside-rules" className="mb-3 text-sm font-semibold">
                    How devnull works
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
                <div
                    className="flex items-center gap-2 font-mono text-xs"
                    data-test="online-developers-count"
                >
                    <span
                        aria-hidden
                        className="size-2 rounded-full bg-emerald-500 motion-safe:animate-pulse"
                    />
                    <span>
                        {onlineDevelopersCount}{' '}
                        {onlineDevelopersCount === 1
                            ? 'developer'
                            : 'developers'}{' '}
                        online
                    </span>
                </div>
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
                <p>Everything else goes to /dev/null.</p>
            </div>
        </aside>
    );
}
