import type { User } from '@/types/auth';
import type { Embed } from '@/types/commit';

/**
 * The providers a stream can be on, mirroring the backend's live URL validation.
 */
export type LivePlatform = 'youtube' | 'twitch';

/**
 * What `User::toArray()` sends as `live` while the user streams, from the `LiveStream` DTO:
 * `{url: string, thumbnail_url: string, platform: 'youtube'|'twitch', ends_at: ISO 8601 string,
 * embed: Embed|null}`. `embed` comes from `App\Services\Embeds::for($url)`, so it is null whenever embeds are off
 * or the provider is not allowed, and the stream then only links out.
 */
export type LiveStream = {
    url: string;
    thumbnail_url: string;
    platform: LivePlatform;
    ends_at: string;
    embed: Embed | null;
};

/**
 * The newest approved top-level commit of someone who is live: `{id: string, body: string (Str::limit 280),
 * created_at: ISO 8601 string}`.
 */
export type LiveCommit = {
    id: string;
    body: string;
    created_at: string;
};

/**
 * One entry of the shared `liveFollowing` prop from `LiveFollowingQuery::get()`: just what the rail and the aside
 * draw of the person, with `live` always set and `latest_commit` (null when they have none). Sorted soonest-ending
 * first, at most 24.
 */
export type LiveFollowingUser = Pick<
    User,
    'id' | 'username' | 'name' | 'avatar_url' | 'lane'
> & {
    live: LiveStream;
    latest_commit: LiveCommit | null;
};
