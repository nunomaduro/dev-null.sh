import type { PointerEvent } from 'react';
import { useEffect, useRef } from 'react';

/**
 * Hover intent for mouse pointers only, so touch and pen fall through to clicks: `onChange(true)` fires after
 * `openDelay` ms over the target, `onChange(false)` after `closeDelay` ms away from it, and moving back in between
 * cancels the pending change. Spread the handlers on every element that counts as "over", such as a trigger and
 * its popover. `cancel` drops any pending change, and unmounting does too.
 */
export function useHoverIntent({
    openDelay = 170,
    closeDelay = 200,
    onChange,
}: {
    openDelay?: number;
    closeDelay?: number;
    onChange: (isHovering: boolean) => void;
}): {
    onPointerEnter: (event: PointerEvent) => void;
    onPointerLeave: (event: PointerEvent) => void;
    cancel: () => void;
} {
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const report = useRef(onChange);

    // The timer outlives the render that set it, so it reports through the latest callback.
    useEffect(() => {
        report.current = onChange;
    });

    useEffect(() => () => clearTimeout(timer.current), []);

    const schedule = (isHovering: boolean) => {
        clearTimeout(timer.current);
        timer.current = setTimeout(
            () => report.current(isHovering),
            isHovering ? openDelay : closeDelay,
        );
    };

    return {
        onPointerEnter: (event) => {
            if (event.pointerType === 'mouse') {
                schedule(true);
            }
        },
        onPointerLeave: (event) => {
            if (event.pointerType === 'mouse') {
                schedule(false);
            }
        },
        cancel: () => clearTimeout(timer.current),
    };
}
