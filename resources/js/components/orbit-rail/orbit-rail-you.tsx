import { Plus } from 'lucide-react';
import { LiveAvatar } from '@/components/live/live-avatar';
import type { OrbitRailSlotProps } from '@/components/orbit-rail/orbit-rail-item';
import { OrbitRailSlot } from '@/components/orbit-rail/orbit-rail-item';
import { UserAvatar } from '@/components/user-avatar';
import { useLiveUntil } from '@/hooks/use-live-until';
import { platformName } from '@/lib/live';
import { cn } from '@/lib/utils';
import type { LiveStream, Profile } from '@/types';

/**
 * The always-first "You" slot that keeps the rail from popping in: a dashed ring with a plus that opens
 * `GoLiveDialog`, or your own live ring whose card offers End stream.
 */
export function OrbitRailYou({
    user,
    ...slot
}: OrbitRailSlotProps & {
    user: Pick<Profile, 'name' | 'username' | 'avatar_url' | 'lane' | 'live'>;
}) {
    if (user.live) {
        return <LiveYou user={user} stream={user.live} {...slot} />;
    }

    return (
        <OrbitRailSlot
            {...slot}
            label="Go live: add your stream link"
            handle="you"
            data-test="orbit-rail-go-live"
        >
            <svg
                viewBox="0 0 44 44"
                aria-hidden
                className={cn(
                    'absolute inset-0 size-11',
                    slot.isActive
                        ? 'text-primary motion-safe:animate-[spin_6s_linear_infinite_reverse]'
                        : 'text-rail motion-safe:animate-[spin_22s_linear_infinite_reverse]',
                )}
            >
                <circle
                    cx="22"
                    cy="22"
                    r="21"
                    className="fill-card"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeDasharray="3 4"
                    strokeLinecap="round"
                />
            </svg>
            <UserAvatar user={user} pixels={72} className="relative size-9" />
            <span
                aria-hidden
                className="absolute -right-0.5 -bottom-0.5 grid size-4 place-items-center rounded-full bg-primary text-primary-foreground ring-2 ring-card"
            >
                <Plus className="size-2.5" strokeWidth={3.5} />
            </span>
        </OrbitRailSlot>
    );
}

function LiveYou({
    user,
    stream,
    ...slot
}: OrbitRailSlotProps & {
    user: Pick<Profile, 'name' | 'avatar_url' | 'lane'>;
    stream: LiveStream;
}) {
    const time = useLiveUntil(stream.ends_at);
    const platform = platformName(stream.platform);

    return (
        <OrbitRailSlot
            {...slot}
            label={
                time === null
                    ? `You are live on ${platform}`
                    : `You are live on ${platform} until ${time.until}`
            }
            handle="you"
            data-test="orbit-rail-you"
        >
            <LiveAvatar user={user} size={36} active={slot.isActive} />
        </OrbitRailSlot>
    );
}
