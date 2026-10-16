import type { CSSProperties, MouseEvent, ReactNode } from 'react';
import { useId, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type { Activity, ActivityBucket, ActivityScale } from '@/types';

const monthNames = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

const weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const weekdayLabels = ['', 'Mon', '', 'Wed', '', 'Fri', ''];

const levelMix = [0, 32, 56, 80, 100];

const windows: Record<ActivityScale, string> = {
    day: 'in the last 24 hours',
    week: 'in the last 7 days',
    month: 'in the last 30 days',
    year: 'in the last year',
};

function levelColor(level: number): string {
    return level === 0
        ? 'var(--muted)'
        : `color-mix(in oklab, var(--lane) ${levelMix[level]}%, var(--card))`;
}

function levelOf(bucket: ActivityBucket, max: number): number {
    if (bucket.level !== undefined) {
        return bucket.level;
    }

    return bucket.count === 0
        ? 0
        : Math.max(1, Math.ceil((bucket.count / max) * 4));
}

type Noun = [singular: string, plural: string];

function counted(count: number, [singular, plural]: Noun): string {
    return `${count.toLocaleString('en-US')} ${count === 1 ? singular : plural}`;
}

function utcDay(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

function monthOf(date: string): number {
    return utcDay(date).getUTCMonth();
}

function formatDay(date: string, withYear: boolean): string {
    const day = utcDay(date);
    const monthDay = `${monthNames[day.getUTCMonth()]} ${day.getUTCDate()}`;

    return withYear
        ? `${monthDay}, ${day.getUTCFullYear()}`
        : `${weekdayNames[day.getUTCDay()]}, ${monthDay}`;
}

function formatTime(time: Date): string {
    return [time.getHours(), time.getMinutes()]
        .map((part) => String(part).padStart(2, '0'))
        .join(':');
}

function describe(
    bucket: ActivityBucket,
    scale: ActivityScale,
    noun: Noun,
): string {
    const commits =
        bucket.count === 0 ? `No ${noun[1]}` : counted(bucket.count, noun);

    if (scale !== 'day') {
        return `${commits} on ${formatDay(bucket.date, scale === 'year')}`;
    }

    const start = new Date(bucket.date);
    const end = new Date(start.getTime() + 3_600_000);
    const isToday = start.toDateString() === new Date().toDateString();

    return `${commits} between ${formatTime(start)} and ${formatTime(end)} ${isToday ? 'today' : 'yesterday'}`;
}

function periodOf(bucket: ActivityBucket, scale: ActivityScale): string {
    return scale === 'day' ? bucket.date.slice(0, 13) : bucket.date;
}

function stripLabel(
    bucket: ActivityBucket,
    scale: ActivityScale,
    index: number,
    length: number,
): string | null {
    if (scale === 'week') {
        const day = utcDay(bucket.date);

        return `${weekdayNames[day.getUTCDay()]} ${day.getUTCDate()}`;
    }

    if (scale === 'day') {
        const start = new Date(bucket.date);

        return start.getHours() % 6 === 0 && index < length - 2
            ? formatTime(start)
            : null;
    }

    const day = utcDay(bucket.date);

    return day.getUTCDay() === 1 && index < length - 3
        ? `${monthNames[day.getUTCMonth()]} ${day.getUTCDate()}`
        : null;
}

type HoveredBucket = {
    bucket: ActivityBucket;
    left: number;
    top: number;
    align: 'start' | 'center' | 'end';
};

type CellsProps = {
    buckets: ActivityBucket[];
    max: number;
    onEnter: (
        bucket: ActivityBucket,
        event: MouseEvent<HTMLSpanElement>,
    ) => void;
    onLeave: () => void;
};

const tooltipHalfWidth = 128;

export function ActivityGraph({
    activity,
    lane,
    onPeriodHover,
    heading = 'Commit activity',
    noun = ['commit', 'commits'],
    className = 'border-b px-4 py-5 sm:px-6',
    summary: customSummary,
    fitWidth = false,
}: {
    activity: Activity;
    lane: string;
    onPeriodHover?: (period: string | null) => void;
    heading?: ReactNode;
    noun?: Noun;
    className?: string;
    summary?: ReactNode;
    // From sm up, the year shrinks to fit a narrow column, such as a commit, instead of scrolling.
    fitWidth?: boolean;
}) {
    const headingId = useId();
    const sectionRef = useRef<HTMLElement>(null);
    const [hovered, setHovered] = useState<HoveredBucket | null>(null);
    const { scale, buckets } = activity;

    const hoverBucket = (
        bucket: ActivityBucket,
        event: MouseEvent<HTMLSpanElement>,
    ) => {
        const section = sectionRef.current?.getBoundingClientRect();
        const cell = event.currentTarget.getBoundingClientRect();

        if (!section) {
            return;
        }

        const left = cell.left - section.left + cell.width / 2;

        setHovered({
            bucket,
            left,
            top: cell.top - section.top,
            align:
                left < tooltipHalfWidth
                    ? 'start'
                    : left > section.width - tooltipHalfWidth
                      ? 'end'
                      : 'center',
        });
        onPeriodHover?.(bucket.count > 0 ? periodOf(bucket, scale) : null);
    };

    const clearHover = () => {
        setHovered(null);
        onPeriodHover?.(null);
    };

    const total = buckets.reduce((sum, bucket) => sum + bucket.count, 0);
    const max = Math.max(1, ...buckets.map((bucket) => bucket.count));
    const isEmpty = total === 0 && scale !== 'year';
    const summary = isEmpty
        ? `No ${noun[1]} yet`
        : `${counted(total, noun)} ${windows[scale]}`;

    return (
        <section
            ref={sectionRef}
            aria-labelledby={headingId}
            className={cn('relative', className)}
            style={{ '--lane': lane } as CSSProperties}
        >
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id={headingId} className="text-sm font-semibold">
                    {heading}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {customSummary !== undefined ? (
                        customSummary
                    ) : isEmpty ? (
                        `No ${noun[1]} yet`
                    ) : (
                        <>
                            <span className="font-mono text-foreground">
                                {total.toLocaleString('en-US')}
                            </span>{' '}
                            {total === 1 ? noun[0] : noun[1]} {windows[scale]}
                        </>
                    )}
                </p>
            </div>

            {scale === 'year' ? (
                <YearGrid
                    buckets={buckets}
                    max={max}
                    summary={summary}
                    fitWidth={fitWidth}
                    onEnter={hoverBucket}
                    onLeave={clearHover}
                />
            ) : (
                <Strip
                    scale={scale}
                    buckets={buckets}
                    max={max}
                    summary={summary}
                    onEnter={hoverBucket}
                    onLeave={clearHover}
                />
            )}

            {hovered && (
                <div
                    aria-hidden
                    className={cn(
                        'pointer-events-none absolute z-10 -translate-y-full rounded-md bg-foreground px-2 py-1 text-xs whitespace-nowrap text-background shadow-md',
                        {
                            '-translate-x-3': hovered.align === 'start',
                            '-translate-x-1/2': hovered.align === 'center',
                            'translate-x-[calc(-100%+0.75rem)]':
                                hovered.align === 'end',
                        },
                    )}
                    data-tooltip
                    style={{ left: hovered.left, top: hovered.top - 6 }}
                >
                    {describe(hovered.bucket, scale, noun)}
                </div>
            )}

            <div
                aria-hidden
                className="mt-2 flex items-center justify-end gap-1 text-xs text-muted-foreground"
            >
                <span className="mr-1">Less</span>
                {levelMix.map((_, level) => (
                    <span
                        key={level}
                        className="size-2.5 rounded-[2px]"
                        style={{ background: levelColor(level) }}
                    />
                ))}
                <span className="ml-1">More</span>
            </div>
        </section>
    );
}

function Strip({
    scale,
    buckets,
    max,
    summary,
    onEnter,
    onLeave,
}: CellsProps & { scale: ActivityScale; summary: string }) {
    const columns = {
        gridTemplateColumns: `repeat(${buckets.length}, minmax(0, 1fr))`,
    };

    return (
        <div role="img" aria-label={summary}>
            <div
                onMouseLeave={onLeave}
                className="grid gap-[3px]"
                style={columns}
            >
                {buckets.map((bucket) => (
                    <span
                        key={bucket.date}
                        onMouseEnter={(event) => onEnter(bucket, event)}
                        className="h-10 rounded-[2px] transition-shadow hover:shadow-[0_0_0_1.5px_var(--foreground)] sm:h-12"
                        style={{
                            background: levelColor(levelOf(bucket, max)),
                        }}
                    />
                ))}
            </div>

            <div
                aria-hidden
                className={cn(
                    'mt-1.5 grid text-xs text-muted-foreground',
                    scale === 'day' && 'font-mono',
                )}
                style={columns}
            >
                {buckets.map((bucket, index) => (
                    <span
                        key={bucket.date}
                        className="overflow-visible whitespace-nowrap"
                    >
                        {stripLabel(bucket, scale, index, buckets.length)}
                    </span>
                ))}
            </div>
        </div>
    );
}

function YearGrid({
    buckets,
    max,
    summary,
    onEnter,
    onLeave,
    fitWidth,
}: CellsProps & { summary: string; fitWidth: boolean }) {
    const weekStarts = buckets.filter((_, index) => index % 7 === 0);
    const monthLabels = weekStarts.map((day, index) => {
        const changesMonth =
            index === 0 ||
            monthOf(day.date) !== monthOf(weekStarts[index - 1].date);
        const nextLabelIsClose =
            index === 0 &&
            weekStarts
                .slice(1, 3)
                .some((week) => monthOf(week.date) !== monthOf(day.date));

        return changesMonth && !nextLabelIsClose
            ? monthNames[monthOf(day.date)]
            : null;
    });

    const columns = {
        gridTemplateColumns: `repeat(${weekStarts.length}, minmax(0, 1fr))`,
    };

    return (
        <div
            ref={(node) => {
                if (node) {
                    node.scrollLeft = node.scrollWidth;
                }
            }}
            className={cn('overflow-x-auto pb-1', {
                'sm:overflow-visible': fitWidth,
            })}
        >
            <div
                role="img"
                aria-label={summary}
                className={cn(
                    'grid min-w-[34rem] grid-cols-[auto_minmax(0,1fr)] gap-x-2 gap-y-1.5',
                    { 'sm:min-w-0': fitWidth },
                )}
            >
                <span aria-hidden />
                <div
                    aria-hidden
                    className="grid text-xs text-muted-foreground"
                    style={columns}
                >
                    {monthLabels.map((label, index) => (
                        <span
                            key={weekStarts[index].date}
                            className="overflow-visible whitespace-nowrap"
                        >
                            {label}
                        </span>
                    ))}
                </div>

                <div
                    aria-hidden
                    className={cn(
                        'grid grid-rows-7 gap-[3px] text-xs text-muted-foreground',
                        { 'sm:gap-[2px]': fitWidth },
                    )}
                >
                    {weekdayLabels.map((label, index) => (
                        <span
                            key={index}
                            className="flex items-center leading-none"
                        >
                            {label}
                        </span>
                    ))}
                </div>

                <div
                    onMouseLeave={onLeave}
                    className={cn('grid grid-flow-col grid-rows-7 gap-[3px]', {
                        'sm:gap-[2px]': fitWidth,
                    })}
                    style={columns}
                >
                    {buckets.map((day) => (
                        <span
                            key={day.date}
                            onMouseEnter={(event) => onEnter(day, event)}
                            className="aspect-square rounded-[2px] transition-shadow hover:shadow-[0_0_0_1.5px_var(--foreground)]"
                            style={{
                                background: levelColor(levelOf(day, max)),
                            }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
