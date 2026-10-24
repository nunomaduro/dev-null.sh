import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import AboutController from '@/actions/App/Http/Controllers/AboutController';
import ChangelogController from '@/actions/App/Http/Controllers/ChangelogController';
import PrivacyController from '@/actions/App/Http/Controllers/PrivacyController';
import TermsController from '@/actions/App/Http/Controllers/TermsController';
import UserFollowersController from '@/actions/App/Http/Controllers/UserFollowersController';
import UserFollowingController from '@/actions/App/Http/Controllers/UserFollowingController';
import { BioText } from '@/components/bio-text';
import { GitHubIcon } from '@/components/github-icon';
import { LiveFollowing } from '@/components/live-following';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { WhoToFollow } from '@/components/who-to-follow';
import { WordmarkText } from '@/components/wordmark-text';
import { useSharedState } from '@/hooks/use-shared-state';
import { commitStateKey } from '@/lib/commit-state';
import { useKeyLabels } from '@/lib/keyboard';
import { exactCountTitle, formatCount } from '@/lib/numbers';
import { laneColor } from '@/lib/utils';
import { login } from '@/routes';
import { show } from '@/routes/user';

export function AppAside({
    onShowShortcuts,
    onSearch,
}: {
    onShowShortcuts: () => void;
    onSearch: () => void;
}) {
    const {
        auth: { user },
        repository,
    } = usePage().props;
    const keys = useKeyLabels();
    const [commitsCount] = useSharedState(
        commitStateKey('commits_count', user?.id ?? ''),
        user?.commits_count ?? 0,
    );

    return (
        <aside className="sticky top-0 hidden h-svh w-80 shrink-0 scrollbar-none flex-col gap-8 overflow-y-auto p-6 xl:flex">
            <button
                type="button"
                onClick={onSearch}
                className="-mb-2 flex h-11 items-center gap-2.5 rounded-lg border bg-card px-4 py-2.5 text-[15px] text-muted-foreground transition-colors hover:border-input hover:text-foreground"
                data-test="search-button"
            >
                <Search className="size-4" />
                Search
                <kbd className="ml-auto rounded-sm border border-b-2 bg-muted px-1.5 font-mono text-xs">
                    {keys.combo(keys.mod, 'K')}
                </kbd>
            </button>

            {user === null ? (
                <section
                    aria-labelledby="aside-sign-in"
                    className="rounded-lg border bg-card p-4"
                >
                    <h2 id="aside-sign-in" className="font-semibold">
                        <WordmarkText>New to /dev/null?</WordmarkText>
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
                    <p className="mt-3 text-xs text-pretty text-muted-foreground">
                        By signing in, you confirm you're at least 16 and agree
                        to the{' '}
                        <Link
                            href={TermsController.show()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            terms
                        </Link>{' '}
                        and the{' '}
                        <Link
                            href={PrivacyController.show()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            privacy policy
                        </Link>
                        .
                    </p>
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
                    <dl className="mt-4 grid grid-cols-3 gap-4 text-center text-sm">
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
                                className="group flex min-w-0 flex-col-reverse"
                            >
                                <dt className="text-muted-foreground">
                                    <Link
                                        href={stat.href}
                                        className="group-hover:underline"
                                    >
                                        {stat.label}
                                    </Link>
                                </dt>
                                <dd className="font-mono font-medium tabular-nums">
                                    <Link
                                        href={stat.href}
                                        className="group-hover:underline"
                                        title={exactCountTitle(stat.count)}
                                    >
                                        {formatCount(stat.count)}
                                    </Link>
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {user.bio && (
                        <p className="mt-3 text-sm text-pretty text-muted-foreground">
                            <BioText bio={user.bio} />
                        </p>
                    )}
                </section>
            )}

            <LiveFollowing />
            <WhoToFollow />

            <div className="-mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t pt-4 text-sm text-muted-foreground">
                <Link
                    href={AboutController.show()}
                    className="transition-colors hover:text-foreground"
                >
                    About
                </Link>
                <nav aria-label="Legal" className="flex gap-4">
                    <Link
                        href={TermsController.show()}
                        className="transition-colors hover:text-foreground"
                    >
                        Terms
                    </Link>
                    <Link
                        href={PrivacyController.show()}
                        className="transition-colors hover:text-foreground"
                    >
                        Privacy
                    </Link>
                </nav>
                <Link
                    href={ChangelogController.index()}
                    className="transition-colors hover:text-foreground"
                >
                    Changelog
                </Link>
                <a
                    href={`https://github.com/${repository}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="transition-colors hover:text-foreground"
                >
                    GitHub
                </a>
            </div>

            <button
                type="button"
                onClick={onShowShortcuts}
                className="mt-auto flex items-center gap-2 self-start rounded-sm text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                Keyboard shortcuts
                <kbd className="min-w-5 rounded-sm border border-b-2 bg-muted px-1 text-center font-mono text-xs">
                    ?
                </kbd>
            </button>
        </aside>
    );
}
