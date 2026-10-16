import { Rocket } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';
import type { ShiptalkerIndex as Index } from '@/types';

const strip =
    'border-b px-4 py-3 font-mono text-[13px] text-muted-foreground sm:px-6';

function plural(count: number, noun: string) {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

/**
 * GitHub contributions against commits here, both since the user joined.
 */
function verdict({ shipped, talked }: Index) {
    if (shipped === 0 && talked === 0) {
        return 'silent';
    }

    if (talked === 0) {
        return 'all ship';
    }

    if (shipped === 0) {
        return 'all talk';
    }

    const ratio = Math.max(shipped, talked) / Math.min(shipped, talked);

    if (ratio < 1.05) {
        return 'balanced';
    }

    return `${ratio < 10 ? ratio.toFixed(1) : Math.round(ratio)}× ${shipped > talked ? 'shipper' : 'talker'}`;
}

export function ShiptalkerIndex({ index }: { index: Index | null }) {
    const total = index ? index.shipped + index.talked : 0;
    const shippedShare = total > 0 && index ? index.shipped / total : 0;

    return (
        <section
            className={strip}
            title={
                index
                    ? `${plural(index.shipped, 'contribution')} on GitHub vs ${plural(index.talked, 'commit')} here since ${new Date(index.since).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}`
                    : undefined
            }
        >
            <p className="flex h-5 items-center justify-between gap-3">
                <span className="flex shrink-0 items-center gap-2">
                    <Rocket className="size-4 text-foreground" />
                    <span className="font-medium text-foreground">
                        shiptalker index
                    </span>
                </span>
                <span className="min-w-0 truncate font-medium text-foreground tabular-nums">
                    {index ? verdict(index) : 'GitHub isn’t answering'}
                </span>
            </p>
            <div
                aria-hidden
                className="mt-1.5 flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-muted"
            >
                {index && index.shipped > 0 && (
                    <span
                        className="h-full bg-sky-500"
                        style={{ width: `${shippedShare * 100}%` }}
                    />
                )}
                {index && index.talked > 0 && (
                    <span className="h-full flex-1 bg-(--lane-1)" />
                )}
            </div>
            <p className="mt-1.5 flex h-4 items-center justify-between gap-3 text-[11px] tabular-nums">
                {index && (
                    <>
                        <span className="truncate">
                            {plural(index.shipped, 'contribution')}
                        </span>
                        <span className="truncate">
                            {plural(index.talked, 'commit')}
                        </span>
                    </>
                )}
            </p>
        </section>
    );
}

export function ShiptalkerIndexSkeleton() {
    return (
        <section aria-hidden className={strip}>
            <div className="animate-in delay-300 duration-300 fill-mode-both fade-in">
                <div className="flex h-5 items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Skeleton className="size-4 rounded-full" />
                        <Skeleton className="h-3 w-28" />
                    </div>
                    <Skeleton className="h-3 w-24" />
                </div>
                <Skeleton className="mt-1.5 h-1.5 rounded-full" />
                <div className="mt-1.5 flex h-4 items-center justify-between gap-3">
                    <Skeleton className="h-2.5 w-28" />
                    <Skeleton className="h-2.5 w-20" />
                </div>
            </div>
        </section>
    );
}
