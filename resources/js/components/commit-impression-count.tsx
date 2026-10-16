import { ChartNoAxesColumn } from 'lucide-react';

export function CommitImpressionCount({ count }: { count: number }) {
    const label = `${count.toLocaleString('en-US')} ${count === 1 ? 'impression' : 'impressions'}`;
    const formatted = new Intl.NumberFormat('en-US', {
        notation: count >= 10_000 ? 'compact' : 'standard',
        maximumFractionDigits: 1,
    }).format(count);

    return (
        <span
            role="img"
            aria-label={label}
            title={label}
            className="inline-flex cursor-default items-center gap-1 px-2 py-0.5 font-mono text-[13px] text-muted-foreground tabular-nums"
            data-test="commit-impressions"
        >
            <ChartNoAxesColumn aria-hidden className="size-4" />
            <span>{formatted}</span>
        </span>
    );
}
