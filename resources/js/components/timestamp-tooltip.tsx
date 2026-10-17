import { usePage } from '@inertiajs/react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { CSSProperties, ReactNode } from 'react';
import { Tooltip, TooltipTrigger } from '@/components/ui/tooltip';
import {
    browserTimeZone,
    formatZonedDate,
    formatZonedTime,
    laneColor,
    timeZoneCity,
} from '@/lib/utils';
import type { User } from '@/types';

export function TimestampTooltip({
    date,
    author,
    children,
}: {
    date: string;
    author: User;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipPrimitive.Portal>
                <TooltipPrimitive.Content
                    sideOffset={6}
                    className="z-50 animate-in rounded-lg border bg-popover text-popover-foreground shadow-md fade-in-0 zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95"
                >
                    <ZonedTimes date={date} author={author} />
                    <TooltipPrimitive.Arrow className="z-50 size-2.5 translate-y-[calc(-50%-1px)] rotate-45 rounded-[2px] border-r border-b bg-popover fill-popover" />
                </TooltipPrimitive.Content>
            </TooltipPrimitive.Portal>
        </Tooltip>
    );
}

function ZonedTimes({ date, author }: { date: string; author: User }) {
    const { auth } = usePage().props;

    const columns = [
        {
            who: 'You',
            lane: auth.user?.lane,
            timeZone: auth.user?.timezone ?? browserTimeZone(),
        },
        ...(author.timezone !== null && author.id !== auth.user?.id
            ? [
                  {
                      who: author.name.split(' ')[0],
                      lane: author.lane,
                      timeZone: author.timezone,
                  },
              ]
            : []),
    ];

    return (
        <div
            className="flex divide-x overflow-hidden rounded-[calc(var(--radius)-1px)]"
            data-test="timestamp-tooltip"
        >
            {columns.map((column) => (
                <div
                    key={column.who}
                    className="border-t-2 border-t-(--lane) px-2.5 py-1.5 whitespace-nowrap"
                    style={
                        {
                            '--lane':
                                column.lane === undefined
                                    ? 'var(--rail)'
                                    : laneColor(column.lane),
                        } as CSSProperties
                    }
                >
                    <p className="text-[11px] text-muted-foreground">
                        {column.who} · {timeZoneCity(column.timeZone)}
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                        <span className="text-[13px] font-semibold text-popover-foreground tabular-nums">
                            {formatZonedTime(date, column.timeZone)}
                        </span>{' '}
                        {formatZonedDate(date, column.timeZone)}
                    </p>
                </div>
            ))}
        </div>
    );
}
