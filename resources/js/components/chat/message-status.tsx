import type { ReactNode } from 'react';
import { cn, laneColor } from '@/lib/utils';
import type { Lane } from '@/types';

export type MessageStatusKind = 'staged' | 'pushed' | 'pulled' | 'rejected';

/**
 * The git-style receipt under the latest message: staged until the server has it, pushed once it does, and
 * pulled once the partner has read it. The dots take each person's lane color.
 */
export function MessageStatusGlyph({
    status,
    lane,
    partnerLane,
    className,
}: {
    status: MessageStatusKind;
    lane: Lane;
    partnerLane: Lane;
    className?: string;
}) {
    const color = laneColor(lane);

    const glyphs: Record<MessageStatusKind, ReactNode> = {
        staged: (
            <circle
                cx="6"
                cy="6"
                r="4"
                fill="none"
                stroke={color}
                strokeWidth="1.5"
                strokeDasharray="2 2"
            />
        ),
        pushed: <circle cx="6" cy="6" r="4" fill={color} />,
        pulled: (
            <>
                <path d="M6 6H16" stroke="var(--rail)" strokeWidth="1.5" />
                <circle cx="6" cy="6" r="4" fill={color} />
                <circle cx="16" cy="6" r="4" fill={laneColor(partnerLane)} />
            </>
        ),
        rejected: (
            <circle
                cx="6"
                cy="6"
                r="4"
                fill="none"
                stroke="var(--destructive)"
                strokeWidth="1.5"
            />
        ),
    };

    return (
        <svg
            aria-hidden
            width={status === 'pulled' ? 22 : 12}
            height="12"
            className={cn('shrink-0', className)}
        >
            {glyphs[status]}
        </svg>
    );
}
