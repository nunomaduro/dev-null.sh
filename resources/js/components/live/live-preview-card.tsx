import { Link } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import { PlatformGlyph } from '@/components/live/platform-glyph';
import {
    StreamPlayer,
    usePlayingStream,
    WatchStreamButton,
} from '@/components/live/stream-player';
import { useLiveUntil } from '@/hooks/use-live-until';
import { useNow } from '@/hooks/use-now';
import { platformName } from '@/lib/live';
import { cn, formatRelativeTime, laneColor, shortHash } from '@/lib/utils';
import { show } from '@/routes/user';
import type { LiveCommit, LiveStream, User } from '@/types';

/**
 * "live until 18:00 · ends in 1h 08m" in the reader's timezone. The line keeps its height before the browser knows
 * the time, so nothing below it moves.
 */
export function LiveUntil({
    endsAt,
    className,
}: {
    endsAt: string;
    className?: string;
}) {
    const live = useLiveUntil(endsAt);

    return (
        <p
            className={cn(
                'font-mono text-[13px] text-muted-foreground tabular-nums',
                className,
            )}
            data-test="live-until"
        >
            {live === null ? (
                ' '
            ) : live.isOver ? (
                'stream ended'
            ) : (
                // Each half wraps as a whole, so a narrow card never splits "10h 49m" or "8:05 AM".
                <>
                    <span className="whitespace-nowrap">
                        live until{' '}
                        <time dateTime={endsAt} className="text-foreground">
                            {live.until}
                        </time>
                    </span>{' '}
                    <span className="whitespace-nowrap">
                        · ends in {live.endsIn}
                    </span>
                </>
            )}
        </p>
    );
}

function LatestCommit({
    user,
    commit,
}: {
    user: Pick<User, 'username' | 'lane'>;
    commit: LiveCommit;
}) {
    const now = useNow();

    return (
        <Link
            href={CommitController.show({ user, commit })}
            className="block rounded-lg border px-3 py-2 transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
            data-test="live-latest-commit"
        >
            <span className="flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
                <span
                    aria-hidden
                    className="size-2 shrink-0 rounded-full bg-(--lane)"
                    style={{ '--lane': laneColor(user.lane) } as CSSProperties}
                />
                <span className="truncate">
                    latest commit ·{' '}
                    <time dateTime={commit.created_at}>
                        {formatRelativeTime(commit.created_at, now)}
                    </time>
                </span>
                <span className="ml-auto shrink-0">{shortHash(commit.id)}</span>
            </span>
            <span className="mt-1 line-clamp-3 text-sm leading-normal wrap-anywhere whitespace-pre-line">
                {commit.body}
            </span>
        </Link>
    );
}

/**
 * What someone is streaming: the player, who it is and where, their latest commit, when it ends, the platform's
 * link-out and `actions` (such as View profile or End stream). It owns its play state and reports it through
 * `onPlayingChange`, so a popover can pin itself open while a stream plays.
 */
export function LivePreviewCard({
    user,
    stream,
    latestCommit,
    actions,
    onPlayingChange,
}: {
    user: Pick<User, 'name' | 'username' | 'avatar_url' | 'lane'>;
    stream: LiveStream;
    latestCommit?: LiveCommit | null;
    actions?: ReactNode;
    onPlayingChange?: (isPlaying: boolean) => void;
}) {
    const [isPlaying, setIsPlaying] = usePlayingStream(stream, onPlayingChange);

    return (
        <div className="@container" data-test="live-preview-card">
            {/* At 520px the thumbnail leaves the details 296px, room for Watch on YouTube and View profile on one row. */}
            <div className="grid gap-3.5 @md:grid-cols-[184px_minmax(0,1fr)]">
                <StreamPlayer
                    user={user}
                    stream={stream}
                    isPlaying={isPlaying}
                    onPlayingChange={setIsPlaying}
                />

                <div className="flex min-w-0 flex-col gap-2.5">
                    <div className="flex min-w-0 items-center gap-2 leading-snug">
                        {/* The handle gives way first, so the name stays readable next to it. */}
                        <Link
                            href={show(user)}
                            className="max-w-2/3 shrink-0 truncate font-semibold hover:underline"
                        >
                            {user.name}
                        </Link>
                        <span className="min-w-0 truncate font-mono text-[13px] text-muted-foreground">
                            @{user.username}
                        </span>
                        <span className="ml-auto flex shrink-0 items-center gap-1.5 text-[13px] text-muted-foreground">
                            <PlatformGlyph
                                platform={stream.platform}
                                className="size-3.5"
                            />
                            {platformName(stream.platform)}
                        </span>
                    </div>

                    {latestCommit && (
                        <LatestCommit user={user} commit={latestCommit} />
                    )}

                    <LiveUntil endsAt={stream.ends_at} />

                    <div className="flex flex-wrap gap-2">
                        <WatchStreamButton stream={stream} />
                        {actions}
                    </div>
                </div>
            </div>
        </div>
    );
}
