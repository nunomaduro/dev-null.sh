import type { RemotesFrom } from '@/types/conversation';
import type { LiveStream } from '@/types/live';
import type { Log } from '@/types/log';

/**
 * A lane from the palette, or a custom hex color.
 */
export type Lane = number | string;

export type User = {
    id: string;
    username: string;
    name: string;
    avatar_url: string | null;
    bio: string | null;
    location: string | null;
    website: string | null;
    company: string | null;
    has_sponsors_listing: boolean;
    has_github_account: boolean;
    lane: Lane;
    timezone: string | null;
    show_local_time: boolean;
    /**
     * Only set while the user is streaming.
     */
    live?: LiveStream | null;
    created_at: string;
    updated_at: string;
};

export type Profile = User & {
    commits_count: number;
    followers_count: number;
    following_count: number;
};

/**
 * Commits and profiles are public, so guests browse them too, without a user or a log.
 */
export type Auth = {
    user: Profile | null;
    isAdmin: boolean;
    automaticTranslation: boolean;
    mustReadCommitRules: boolean;
    log: Log | null;
    unreadNotificationsCount: number;
    unreadMessagesCount: number;
    remotes: {
        from: RemotesFrom;
        pull_receipts: boolean;
        requests: boolean;
        everyone: boolean;
    } | null;
    pasteis: { remaining: number; day: string } | null;
};

export type SuggestedUser = User & {
    followed_by_following_count: number;
    followers_count: number;
    follows_you: boolean;
};
