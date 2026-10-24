import { Clock } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { cn, formatUtcOffset, formatZonedTime } from '@/lib/utils';

const MINUTE = 60_000;

function subscribe(onChange: () => void): () => void {
    const interval = setInterval(onChange, MINUTE);

    return () => clearInterval(interval);
}

/**
 * Snapshots are whole minutes, so the clock re-renders when the minute turns rather than on every read.
 */
function currentMinute(): number {
    return Math.floor(Date.now() / MINUTE) * MINUTE;
}

/**
 * What time it is for someone who chose to share their timezone. It renders nothing on the server, where the
 * current time is not the reader's.
 */
export function LocalTime({
    timeZone,
    as: Element = 'li',
    className,
}: {
    timeZone: string;
    as?: 'li' | 'p';
    className?: string;
}) {
    const minute = useSyncExternalStore(subscribe, currentMinute, () => null);

    if (minute === null) {
        return null;
    }

    const now = new Date(minute);

    return (
        <Element
            className={cn('flex items-center gap-1.5', className)}
            data-test="local-time"
        >
            <Clock className="size-4" />
            <span>
                <time dateTime={now.toISOString()}>
                    {formatZonedTime(now.toISOString(), timeZone)}
                </time>{' '}
                ({formatUtcOffset(now, timeZone)})
            </span>
        </Element>
    );
}
