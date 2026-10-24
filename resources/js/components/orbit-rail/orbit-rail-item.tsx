import type { PointerEvent, ReactNode, Ref } from 'react';
import { LiveAvatar } from '@/components/live/live-avatar';
import { useLiveUntil } from '@/hooks/use-live-until';
import { platformName } from '@/lib/live';
import { cn } from '@/lib/utils';
import type { LiveFollowingUser } from '@/types';

/**
 * What every slot on the rail takes from `OrbitRail`, which owns the open card, the hover intent and the roving
 * tabindex (keys are handled on the rail, so slots only report focus).
 */
export type OrbitRailSlotProps = {
    ref?: Ref<HTMLButtonElement>;
    isActive: boolean;
    tabIndex: 0 | -1;
    onPointerEnter: (event: PointerEvent) => void;
    onPointerLeave: (event: PointerEvent) => void;
    onClick: () => void;
    onFocus: () => void;
};

/**
 * One person on the rail: a button with their `LiveAvatar` and @handle, labelled "{name} is live on {Platform}
 * until 18:00", with `aria-expanded` and `aria-haspopup="dialog"` for the card it opens.
 */
export function OrbitRailItem({
    user,
    ...slot
}: OrbitRailSlotProps & { user: LiveFollowingUser }) {
    const time = useLiveUntil(user.live.ends_at);
    const platform = platformName(user.live.platform);

    return (
        <OrbitRailSlot
            {...slot}
            label={
                time === null
                    ? `${user.name} is live on ${platform}`
                    : `${user.name} is live on ${platform} until ${time.until}`
            }
            handle={user.username}
            // Someone who goes live between polls joins the rail instead of appearing in it.
            className="motion-safe:animate-in motion-safe:duration-600 motion-safe:ease-[cubic-bezier(0.2,0.9,0.2,1)] motion-safe:zoom-in-90 motion-safe:fade-in"
            data-test="orbit-rail-item"
        >
            <LiveAvatar user={user} size={36} active={slot.isActive} />
        </OrbitRailSlot>
    );
}

/**
 * The 56px-wide button every rail slot is drawn with: a 44px ring area that lifts while hovered or open, and a
 * one-line mono caption under it. The rail is a list, so each slot is its own list item.
 */
export function OrbitRailSlot({
    ref,
    isActive,
    tabIndex,
    onPointerEnter,
    onPointerLeave,
    onClick,
    onFocus,
    label,
    handle,
    className,
    'data-test': dataTest,
    children,
}: OrbitRailSlotProps & {
    label: string;
    handle: string;
    className?: string;
    'data-test'?: string;
    children: ReactNode;
}) {
    return (
        <li className={cn('flex snap-start', className)}>
            <button
                ref={ref}
                type="button"
                tabIndex={tabIndex}
                aria-label={label}
                aria-haspopup="dialog"
                aria-expanded={isActive}
                title={label}
                onPointerEnter={onPointerEnter}
                onPointerLeave={onPointerLeave}
                onClick={onClick}
                onFocus={onFocus}
                className="group relative flex w-14 shrink-0 cursor-pointer flex-col items-center rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                data-test={dataTest}
            >
                <span
                    className={cn(
                        'relative grid size-11 place-items-center motion-safe:transition-transform motion-safe:duration-300 motion-safe:ease-[cubic-bezier(0.2,0.9,0.2,1)]',
                        isActive ? 'scale-108' : 'group-hover:scale-108',
                    )}
                >
                    {children}
                </span>
                {/* Clear of the LIVE pill hanging off the ring, even while the ring is lifted. */}
                <span
                    className={cn(
                        'mt-2.5 block max-w-14 truncate font-mono text-[10px] leading-3.5',
                        isActive
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    {handle}
                </span>
            </button>
        </li>
    );
}
