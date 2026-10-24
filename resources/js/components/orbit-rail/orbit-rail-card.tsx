import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { useMountAnimations } from '@/hooks/use-mount-animations';

const focusable =
    'button:not([disabled]), a[href], input:not([disabled]), select, textarea, iframe, [tabindex]:not([tabindex="-1"])';

/**
 * The open card's chrome under the rail: a lane line dropping from the ring into a node on the card's top edge, and
 * the card blooming out of that point. Keyed by whose card it is, so every swap blooms again and starts with a fresh
 * player. A new `focusRequest` moves focus into the card, as a click or Enter on the slot does.
 */
export function OrbitRailCard({
    lane,
    originX,
    focusRequest,
    children,
}: {
    lane: string;
    originX: number;
    focusRequest: number | null;
    children: ReactNode;
}) {
    const card = useRef<HTMLDivElement>(null);
    const stem = useRef<HTMLSpanElement>(null);
    const node = useRef<HTMLSpanElement>(null);
    // The bloom opens from where the ring was when the card appeared; scrolling the rail afterwards only moves the line.
    useMountAnimations(() => [
        card.current?.animate(
            [
                {
                    clipPath: `circle(0px at ${originX}px 0px)`,
                    opacity: 0.3,
                    transform: 'translateY(-8px) scale(0.985)',
                },
                {
                    clipPath: `circle(160% at ${originX}px 0px)`,
                    opacity: 1,
                    transform: 'none',
                },
            ],
            { duration: 620, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' },
        ),
        stem.current?.animate(
            { transform: ['scaleY(0)', 'scaleY(1)'] },
            { duration: 220, easing: 'ease-out' },
        ),
        node.current?.animate(
            { transform: ['scale(0)', 'scale(1.3)', 'scale(1)'] },
            {
                duration: 500,
                delay: 120,
                easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
                fill: 'backwards',
            },
        ),
    ]);

    useEffect(() => {
        if (focusRequest === null) {
            return;
        }

        const element = card.current;
        (element?.querySelector<HTMLElement>(focusable) ?? element)?.focus();
    }, [focusRequest]);

    return (
        <>
            <span
                ref={stem}
                aria-hidden
                className="absolute -top-2 h-5.5 w-0.5 origin-top"
                style={{ left: originX - 1, background: lane }}
            />
            <span
                ref={node}
                aria-hidden
                className="absolute top-1.75 z-10 size-3.5"
                style={{ left: originX - 7 }}
            >
                <span
                    className="absolute inset-0 rounded-full border-2 motion-safe:animate-[ping_1.8s_cubic-bezier(0,0,0.2,1)_500ms_infinite]"
                    style={{ borderColor: lane }}
                />
                <span
                    className="absolute inset-0 rounded-full border-2 shadow-[inset_0_0_0_2px_var(--popover)]"
                    style={{ borderColor: lane, background: lane }}
                />
            </span>
            <div
                ref={card}
                tabIndex={-1}
                className="relative overflow-hidden rounded-lg border bg-popover p-3 text-popover-foreground shadow-[0_12px_32px_rgb(18_20_23/0.14),0_2px_6px_rgb(18_20_23/0.08)] outline-none dark:shadow-[0_12px_32px_rgb(0_0_0/0.45),0_2px_6px_rgb(0_0_0/0.3)]"
            >
                {children}
            </div>
        </>
    );
}
