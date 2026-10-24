import { usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { LiveAvatar } from '@/components/live/live-avatar';
import { useLiveUntil } from '@/hooks/use-live-until';
import { useNow } from '@/hooks/use-now';
import { visibleLive } from '@/lib/orbit-rail';
import type { LiveFollowingUser } from '@/types';

/** The side column has room for a handful; the Orbit Rail on the logs shows everyone. */
const limit = 5;

/**
 * The aside's "Live now" list: the five streams ending soonest, in the order the server sends them. Log pages leave it
 * out because the Orbit Rail above the feed already shows the same people.
 */
export function LiveFollowing() {
    const { component, props } = usePage();
    const now = useNow();
    // A stream that ends drops out with the clock, and the next one moves up into its place.
    const users = visibleLive(props.liveFollowing ?? [], now).slice(0, limit);

    if (component === 'log/show' || users.length === 0) return null;

    return (
        <section
            aria-labelledby="aside-live-following"
            className="-mt-4 rounded-lg border bg-card p-3"
            data-test="live-following"
        >
            <h2
                id="aside-live-following"
                className="mb-1.5 flex items-center justify-between text-sm font-semibold"
            >
                Live now
            </h2>
            <ul className="space-y-0.5">
                {users.map((user) => (
                    <LiveFollowingItem key={user.id} user={user} />
                ))}
            </ul>
        </section>
    );
}

function LiveFollowingItem({ user }: { user: LiveFollowingUser }) {
    const live = useLiveUntil(user.live.ends_at);

    return (
        <li>
            <a
                href={user.live.url}
                target="_blank"
                rel="noopener noreferrer"
                className="group -mx-2 flex items-center gap-2.5 rounded-lg px-2 py-2 transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary"
                aria-label={
                    live
                        ? `Watch ${user.name} live until ${live.until}`
                        : `Watch ${user.name} live`
                }
            >
                <span className="mb-1.5 flex shrink-0">
                    <LiveAvatar user={user} size={28} />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">
                        {user.name}
                    </span>
                    <span className="block truncate font-mono text-xs text-muted-foreground tabular-nums">
                        @{user.username}
                        {live && ` · until ${live.until}`}
                    </span>
                </span>
                <ArrowUpRight
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                />
            </a>
        </li>
    );
}
