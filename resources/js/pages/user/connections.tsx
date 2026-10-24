import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { CSSProperties } from 'react';
import UserFollowersController from '@/actions/App/Http/Controllers/UserFollowersController';
import UserFollowersYouKnowController from '@/actions/App/Http/Controllers/UserFollowersYouKnowController';
import UserFollowingController from '@/actions/App/Http/Controllers/UserFollowingController';
import { EmptyFeed } from '@/components/commit-feed';
import { FollowButton } from '@/components/follow-button';
import { PageTabs } from '@/components/page-tabs';
import { Spinner } from '@/components/ui/spinner';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import { laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { CursorPaginated, User } from '@/types';

type Connection = User & { is_followed: boolean };

function ConnectionFollowButton({ person }: { person: Connection }) {
    return (
        <FollowButton
            user={person}
            isFollowing={person.is_followed}
            size="sm"
            className="mt-4 min-w-24 shrink-0"
            only={['auth']}
            preserveState
            onSuccess={() => router.flushAll()}
        />
    );
}

export default function Connections({
    user,
    type,
    users,
}: {
    user: User;
    type: 'following' | 'followers' | 'followers_you_know';
    users: CursorPaginated<Connection>;
}) {
    const { auth } = usePage().props;
    const title = {
        followers: 'Followers',
        followers_you_know: 'Followers you know',
        following: 'Following',
    }[type];
    const knowsFollowers = auth.user !== null && auth.user.id !== user.id;

    return (
        <>
            <Head title={`${title} · @${user.username}`} />

            <div className="sticky top-0 z-10 bg-card">
                <div className="flex h-14 items-center gap-3 border-b px-4 sm:px-6">
                    <Link
                        href={show(user)}
                        aria-label={`Back to @${user.username}`}
                        className="text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>
                    <h1 className="min-w-0 truncate font-semibold">
                        {user.name}
                    </h1>
                    <p className="shrink-0 font-mono text-[13px] text-muted-foreground">
                        @{user.username}
                    </p>
                </div>
                <PageTabs
                    label="Connections"
                    tabs={[
                        {
                            title: 'Followers',
                            href: UserFollowersController.index(user),
                            isActive: type === 'followers',
                        },
                        ...(knowsFollowers
                            ? [
                                  {
                                      title: 'Followers you know',
                                      shortTitle: 'You know',
                                      href: UserFollowersYouKnowController.index(
                                          user,
                                      ),
                                      isActive: type === 'followers_you_know',
                                  },
                              ]
                            : []),
                        {
                            title: 'Following',
                            href: UserFollowingController.index(user),
                            isActive: type === 'following',
                        },
                    ]}
                />
            </div>

            {users.data.length === 0 ? (
                <div className="px-6 py-16 text-center sm:px-12">
                    <EmptyFeed
                        command={
                            type === 'followers_you_know'
                                ? 'fatal: no followers you know'
                                : `fatal: no ${type} yet`
                        }
                    >
                        {
                            {
                                followers: `Nobody is following @${user.username} yet.`,
                                followers_you_know: `Nobody you follow is following @${user.username} yet.`,
                                following: `@${user.username} isn't following anyone yet.`,
                            }[type]
                        }
                    </EmptyFeed>
                </div>
            ) : (
                <InfiniteScroll
                    data="users"
                    onlyNext
                    preserveUrl
                    loading={
                        <div className="flex justify-center py-6">
                            <Spinner className="text-muted-foreground" />
                        </div>
                    }
                >
                    <ul className="divide-y">
                        {users.data.map((person) => (
                            <li
                                key={person.id}
                                className="flex items-start gap-3 pr-4 hover:bg-muted/50 sm:pr-6"
                            >
                                <Link
                                    href={show(person)}
                                    className="flex min-w-0 flex-1 items-start gap-3 py-3 pl-4 sm:pl-6"
                                >
                                    <UserAvatar
                                        user={person}
                                        className="size-10 ring-2 ring-(--lane) ring-offset-2 ring-offset-card"
                                        style={
                                            {
                                                '--lane': laneColor(
                                                    person.lane,
                                                ),
                                            } as CSSProperties
                                        }
                                    />
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {person.name}
                                        </p>
                                        <p className="truncate font-mono text-sm text-muted-foreground">
                                            @{person.username}
                                        </p>
                                        {person.bio && (
                                            <p className="mt-1 line-clamp-2 text-sm">
                                                {person.bio}
                                            </p>
                                        )}
                                    </div>
                                </Link>
                                {person.id !== auth.user?.id && (
                                    <ConnectionFollowButton person={person} />
                                )}
                            </li>
                        ))}
                    </ul>
                </InfiniteScroll>
            )}
        </>
    );
}

Connections.layout = AppLayout;
