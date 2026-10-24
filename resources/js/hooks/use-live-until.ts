import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { useNow } from '@/hooks/use-now';
import { formatEndsIn, formatLiveUntil, isStreaming } from '@/lib/live';

const subscribe = () => () => {};

/**
 * When a stream ends, ticking with `useNow`: `until` like "18:00" in the timezone the viewer chose (their browser's
 * when they chose none, like every other time on the site), `endsIn` like "1h 08m", and `isOver` once `endsAt` has
 * passed. Null during SSR and the first render, so server and client markup match like `local-time.tsx`.
 */
export function useLiveUntil(
    endsAt: string,
): { until: string; endsIn: string; isOver: boolean } | null {
    const isClient = useSyncExternalStore(
        subscribe,
        () => true,
        () => false,
    );
    const now = useNow();
    const timeZone = usePage().props.auth.user?.timezone ?? undefined;

    if (!isClient) {
        return null;
    }

    return {
        until: formatLiveUntil(endsAt, timeZone),
        endsIn: formatEndsIn(endsAt, now),
        isOver: !isStreaming({ ends_at: endsAt }, now),
    };
}
