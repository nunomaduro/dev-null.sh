import type { Log } from '@/types/log';

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
    lane: number;
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
    log: Log | null;
    unreadNotificationsCount: number;
    unreadMessagesCount?: number;
};

export type SuggestedUser = User & {
    followed_by_following_count: number;
    followers_count: number;
};
