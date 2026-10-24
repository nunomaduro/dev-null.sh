import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    Heart,
    Link2,
    MapPin,
    MessagesSquare,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { show } from '@/actions/App/Http/Controllers/UserController';
import UserFollowersController from '@/actions/App/Http/Controllers/UserFollowersController';
import UserFollowingController from '@/actions/App/Http/Controllers/UserFollowingController';
import { AchievementsDialog } from '@/components/achievements-dialog';
import { AchievementsShelf } from '@/components/achievements-shelf';
import { ActivityGraph } from '@/components/activity-graph';
import { ActivityStreaks } from '@/components/activity-streaks';
import { BioText } from '@/components/bio-text';
import { CommitFeed, EmptyFeed } from '@/components/commit-feed';
import { EditProfileMenu } from '@/components/edit-profile-menu';
import { FollowButton } from '@/components/follow-button';
import { FollowersYouKnow } from '@/components/followers-you-know';
import type { FollowersYouKnowPreview } from '@/components/followers-you-know';
import { GitHubIcon } from '@/components/github-icon';
import {
    LiveAvatarMarker,
    LiveStreamTrigger,
} from '@/components/live-stream-card';
import { LocalTime } from '@/components/local-time';
import { PageTabs } from '@/components/page-tabs';
import { PinCommitButton } from '@/components/pin-commit-button';
import { ProfileActionsMenu } from '@/components/profile-actions-menu';
import { ProfileShareButton } from '@/components/profile-share-button';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import type { UserModeration } from '@/components/user-moderation-menu';
import {
    UserModerationMenu,
    UserModerationStatus,
} from '@/components/user-moderation-menu';
import { UserSafetyMenu } from '@/components/user-safety-menu';
import { refreshSharedState, useSharedState } from '@/hooks/use-shared-state';
import AppLayout from '@/layouts/app-layout';
import { commitStateKey } from '@/lib/commit-state';
import { exactCountTitle, formatCount } from '@/lib/numbers';
import { dateLocale, laneColor, laneTint } from '@/lib/utils';
import type {
    Achievements,
    Activity,
    Commit,
    CursorPaginated,
    Profile,
    Streaks,
} from '@/types';

function pluralize(count: number, singular: string, plural: string) {
    return count === 1 ? singular : plural;
}

export default function Show({
    user,
    pasteisReceived,
    isFollowing,
    followsYou,
    isMuting,
    followersYouKnow,
    canPush,
    moderation,
    activity,
    streaks,
    achievements,
    commits,
    tab,
    pinnedCommit,
}: {
    user: Profile;
    pasteisReceived: number | null;
    isFollowing: boolean;
    followsYou: boolean;
    isMuting: boolean;
    followersYouKnow: FollowersYouKnowPreview | null;
    canPush: boolean;
    moderation: UserModeration | null;
    activity: Activity;
    streaks: Streaks;
    achievements: Achievements;
    commits: CursorPaginated<Commit>;
    tab: 'commits' | 'replies';
    pinnedCommit: Commit | null;
}) {
    const { auth } = usePage().props;
    const isCurrentUser = auth.user?.id === user.id;
    const lane = laneColor(user.lane);
    const [highlightedPeriod, setHighlightedPeriod] = useState<string | null>(
        null,
    );
    const [isAchievementsOpen, setIsAchievementsOpen] = useState(false);
    const [pasteisCount] = useSharedState(
        commitStateKey('pasteis_received', user.id),
        pasteisReceived ?? 0,
    );

    useEffect(() => {
        if (pasteisReceived !== null) {
            refreshSharedState(
                new Map([
                    [
                        commitStateKey('pasteis_received', user.id),
                        pasteisReceived,
                    ],
                ]),
            );
        }
    }, [user.id, pasteisReceived]);

    return (
        <>
            <Head title={`${user.name} (@${user.username})`} />

            <div className="sticky top-0 z-10 flex h-14 items-center gap-3 border-b bg-card px-4 sm:px-6">
                <p className="min-w-0 truncate font-semibold">{user.name}</p>
                <p
                    className="shrink-0 font-mono text-[13px] text-muted-foreground"
                    title={exactCountTitle(user.commits_count)}
                >
                    {formatCount(user.commits_count)}{' '}
                    {pluralize(user.commits_count, 'commit', 'commits')}
                </p>
            </div>

            <header className="border-b px-4 pt-6 pb-5 sm:px-6">
                <div className="flex items-start justify-between gap-4">
                    {user.live ? (
                        <LiveStreamTrigger
                            user={user}
                            showProfile={false}
                            className="flex"
                        >
                            <a
                                href={user.live.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={`Watch ${user.name} live on ${user.live.url.includes('twitch.tv') ? 'Twitch' : 'YouTube'}`}
                                className="relative flex rounded-full"
                                data-test="profile-live-avatar"
                            >
                                <UserAvatar
                                    user={user}
                                    className="size-20 text-2xl ring-2 ring-red-600 ring-offset-4 ring-offset-card"
                                    pixels={160}
                                />
                                <LiveAvatarMarker className="-bottom-2.5" />
                            </a>
                        </LiveStreamTrigger>
                    ) : (
                        <UserAvatar
                            user={user}
                            className="size-20 text-2xl ring-2 ring-(--lane) ring-offset-4 ring-offset-card"
                            pixels={160}
                            style={{ '--lane': lane } as CSSProperties}
                        />
                    )}

                    <div className="flex shrink-0 gap-1.5 sm:gap-2">
                        <ProfileShareButton
                            username={user.username}
                            href={show(user).url}
                        />
                        {isCurrentUser ? (
                            <>
                                <ProfileActionsMenu user={user} />
                                <EditProfileMenu />
                            </>
                        ) : (
                            <>
                                {moderation && (
                                    <UserModerationMenu
                                        user={user}
                                        moderation={moderation}
                                    />
                                )}
                                {auth.user && (
                                    <UserSafetyMenu
                                        user={user}
                                        isMuting={isMuting}
                                        isBlocking={false}
                                    />
                                )}
                                {canPush && (
                                    <Button
                                        variant="outline"
                                        className="sm:min-w-24"
                                        asChild
                                    >
                                        <Link
                                            href={ConversationController.show(
                                                user,
                                            )}
                                            aria-label={`Remote @${user.username}`}
                                            data-test="profile-remote"
                                        >
                                            <MessagesSquare />
                                            <span className="hidden sm:inline">
                                                Remote
                                            </span>
                                        </Link>
                                    </Button>
                                )}
                                <FollowButton
                                    user={user}
                                    isFollowing={isFollowing}
                                    className="min-w-28"
                                    onSuccess={() => router.flushAll()}
                                />
                            </>
                        )}
                    </div>
                </div>

                <h1 className="mt-4 text-2xl font-semibold tracking-tight text-balance">
                    {user.name}
                </h1>
                <div className="flex items-center gap-2">
                    <p className="flex items-center gap-1.5 font-mono text-sm text-muted-foreground">
                        <span
                            aria-hidden
                            className="size-2 rounded-full"
                            style={{ background: lane }}
                        />
                        @{user.username}
                        {followsYou && (
                            <span className="ml-1 shrink-0 rounded bg-muted px-1.5 py-0.5 font-sans text-xs">
                                Follows you
                            </span>
                        )}
                        {isMuting && (
                            <span className="ml-1 rounded bg-muted px-1.5 py-0.5 font-sans text-xs">
                                Muted
                            </span>
                        )}
                    </p>
                    <button
                        type="button"
                        onClick={() => setIsAchievementsOpen(true)}
                        className="inline-flex cursor-pointer items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium transition hover:brightness-110"
                        style={{
                            borderColor: laneTint(lane, 25),
                            backgroundColor: laneTint(lane, 8),
                            color: lane,
                        }}
                        title="View achievements and Unix rank"
                    >
                        <span>
                            Lv. {achievements.level} · {achievements.rank}
                        </span>
                    </button>
                </div>

                {moderation && <UserModerationStatus moderation={moderation} />}

                {user.bio && (
                    <p className="mt-3 max-w-prose text-[15px] leading-relaxed wrap-break-word whitespace-pre-wrap">
                        <BioText bio={user.bio} />
                    </p>
                )}

                <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
                    {user.company && (
                        <li className="flex items-center gap-1.5">
                            <Building2 className="size-4" />
                            <span>
                                <BioText bio={user.company} />
                            </span>
                        </li>
                    )}
                    {user.location && (
                        <li className="flex items-center gap-1.5">
                            <MapPin className="size-4" />
                            {user.location}
                        </li>
                    )}
                    {user.show_local_time && user.timezone && (
                        <LocalTime timeZone={user.timezone} />
                    )}
                    {user.website && (
                        <li className="flex min-w-0 items-center gap-1.5">
                            <Link2 className="size-4 shrink-0" />
                            <a
                                href={user.website}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                className="truncate text-primary hover:underline"
                            >
                                {user.website.replace(/^https?:\/\//u, '')}
                            </a>
                        </li>
                    )}
                    {user.has_github_account && (
                        <li className="flex items-center gap-1.5">
                            <GitHubIcon className="size-4" />
                            <a
                                href={`https://github.com/${user.username}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary hover:underline"
                            >
                                github.com/{user.username}
                            </a>
                        </li>
                    )}
                    {user.has_sponsors_listing && (
                        <li className="flex items-center gap-1.5">
                            <Heart className="size-4" />
                            <a
                                href={`https://github.com/sponsors/${user.username}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary hover:underline"
                            >
                                Sponsor on GitHub
                            </a>
                        </li>
                    )}
                    <li className="flex items-center gap-1.5">
                        <CalendarDays className="size-4" />
                        Joined{' '}
                        {new Date(user.created_at).toLocaleDateString(
                            dateLocale,
                            { month: 'long', year: 'numeric' },
                        )}
                    </li>
                </ul>

                <dl className="mt-4 flex gap-5 text-sm">
                    {[
                        {
                            count: user.commits_count,
                            label: pluralize(
                                user.commits_count,
                                'commit',
                                'commits',
                            ),
                            href: show(user),
                        },
                        {
                            count: user.following_count,
                            label: 'following',
                            href: UserFollowingController.index(user),
                        },
                        {
                            count: user.followers_count,
                            label: pluralize(
                                user.followers_count,
                                'follower',
                                'followers',
                            ),
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
                                    title={exactCountTitle(stat.count)}
                                >
                                    {formatCount(stat.count)}
                                </Link>
                            </dd>
                        </div>
                    ))}
                </dl>
                {followersYouKnow && (
                    <FollowersYouKnow user={user} preview={followersYouKnow} />
                )}
                {auth.pasteis && pasteisReceived !== null && (
                    <p
                        className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground"
                        data-test="profile-pasteis-count"
                    >
                        <img
                            src="/pastel-de-nata.svg"
                            alt=""
                            width={20}
                            height={20}
                            className="size-5 shrink-0 object-contain"
                        />
                        <span
                            className="font-mono font-medium text-foreground tabular-nums"
                            title={exactCountTitle(pasteisCount)}
                        >
                            {formatCount(pasteisCount)}
                        </span>
                        {pluralize(pasteisCount, 'pastel', 'pastéis')} received
                    </p>
                )}
            </header>

            <ActivityGraph
                activity={activity}
                lane={lane}
                onPeriodHover={setHighlightedPeriod}
            />

            <ActivityStreaks streaks={streaks} lane={lane} />

            <AchievementsShelf
                achievements={achievements}
                lane={lane}
                onInspect={() => setIsAchievementsOpen(true)}
            />

            <AchievementsDialog
                open={isAchievementsOpen}
                onOpenChange={setIsAchievementsOpen}
                achievements={achievements}
                user={user}
                lane={lane}
            />

            <div className="min-h-dvh">
                <PageTabs
                    label="Profile feed"
                    preserveScroll
                    only={['tab', 'commits', 'pinnedCommit']}
                    reset={['commits']}
                    tabs={[
                        {
                            title: 'Commits',
                            href: show(user),
                            isActive: tab === 'commits',
                        },
                        {
                            title: 'Replies',
                            href: show(user, { query: { tab: 'replies' } }),
                            isActive: tab === 'replies',
                        },
                    ]}
                />

                <CommitFeed
                    key={`${user.id}:${tab}`}
                    commits={commits}
                    pinnedCommit={tab === 'commits' ? pinnedCommit : null}
                    threadActions={
                        isCurrentUser && tab === 'commits'
                            ? (commit) => (
                                  <PinCommitButton
                                      commit={commit}
                                      isPinned={commit.id === pinnedCommit?.id}
                                  />
                              )
                            : undefined
                    }
                    highlightedPeriod={highlightedPeriod}
                    end={
                        tab === 'replies'
                            ? isCurrentUser
                                ? 'That was your first reply.'
                                : `That's @${user.username}'s first reply.`
                            : isCurrentUser
                              ? 'That was your first commit. Everyone starts somewhere.'
                              : `That's @${user.username}'s root commit.`
                    }
                    empty={
                        tab === 'replies' ? (
                            <EmptyFeed command="fatal: no replies yet">
                                {isCurrentUser
                                    ? 'Your replies show up here. Join a conversation from your log.'
                                    : `@${user.username} hasn't replied to anything yet.`}
                            </EmptyFeed>
                        ) : isCurrentUser ? (
                            <EmptyFeed command="fatal: you do not have any commits yet">
                                Your commits show up here. Make your first one
                                from your log.
                            </EmptyFeed>
                        ) : (
                            <EmptyFeed command="fatal: no commits yet">
                                @{user.username} hasn't committed anything yet.
                            </EmptyFeed>
                        )
                    }
                />
            </div>
        </>
    );
}

Show.layout = AppLayout;
