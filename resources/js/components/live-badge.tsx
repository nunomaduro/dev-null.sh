import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function LiveBadge({ className, ...props }: ComponentProps<'span'>) {
    return (
        <span
            {...props}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-sm bg-red-600 px-1.5 py-px font-mono text-[10px] leading-4 font-semibold tracking-wide text-white uppercase',
                className,
            )}
            data-test="live-badge"
        >
            <span
                aria-hidden
                className="size-1.5 rounded-full bg-white motion-safe:animate-pulse"
            />
            Live
        </span>
    );
}
