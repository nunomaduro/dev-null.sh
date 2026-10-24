import type { LivePlatform, LiveStream } from '@/types';

// Only type imports from `@/` here: `tests/Unit/*.test.ts` load this file straight from node, which cannot resolve them.

const MINUTE = 60_000;
const THUMBNAIL_BUCKET = 5 * MINUTE;

const platformNames: Record<LivePlatform, string> = {
    youtube: 'YouTube',
    twitch: 'Twitch',
};

const watchLabels: Record<LivePlatform, string> = {
    youtube: 'Watch on YouTube',
    twitch: 'Join on Twitch',
};

/**
 * Whether the stream is still on at `now`, which is the moment `ends_at` has not passed yet.
 */
export function isStreaming(
    stream: Pick<LiveStream, 'ends_at'>,
    now: Date,
): boolean {
    return new Date(stream.ends_at).getTime() > now.getTime();
}

// Building a formatter is what costs, about half a millisecond each, so every ring on the rail shares one per timezone.
const clockFormats = new Map<string | undefined, Intl.DateTimeFormat>();

/**
 * The clock time a stream ends at in a timezone (the browser's own when omitted), like "18:00" or "6:00 PM" in the
 * browser's locale, the same format as `formatZonedTime`.
 */
export function formatLiveUntil(endsAt: string, timeZone?: string): string {
    let format = clockFormats.get(timeZone);

    if (format === undefined) {
        format = new Intl.DateTimeFormat(undefined, {
            hour: 'numeric',
            minute: '2-digit',
            timeZone,
        });
        clockFormats.set(timeZone, format);
    }

    return format.format(new Date(endsAt));
}

/**
 * How long until the stream ends, in whole minutes rounded up: "2h 10m", "1h 08m", "45m", and "0m" once it is over.
 */
export function formatEndsIn(endsAt: string, now: Date): string {
    const left = new Date(endsAt).getTime() - now.getTime();
    const minutes = left > 0 ? Math.ceil(left / MINUTE) : 0;
    const hours = Math.floor(minutes / 60);

    if (hours === 0) {
        return `${minutes}m`;
    }

    return `${hours}h ${String(minutes % 60).padStart(2, '0')}m`;
}

/**
 * The provider's display name: "YouTube" or "Twitch".
 */
export function platformName(platform: LivePlatform): string {
    return platformNames[platform];
}

/**
 * The link-out label in each provider's own words: "Watch on YouTube" or "Join on Twitch".
 */
export function watchLabel(platform: LivePlatform): string {
    return watchLabels[platform];
}

/**
 * The thumbnail URL with a `t` param set to the current 5-minute bucket, so polling refreshes a stale frame while
 * every render inside the same bucket keeps the same URL (and the browser cache).
 */
export function thumbnailUrl(
    stream: Pick<LiveStream, 'thumbnail_url'>,
    now: Date,
): string {
    const bucket = String(Math.floor(now.getTime() / THUMBNAIL_BUCKET));

    try {
        const url = new URL(stream.thumbnail_url);

        url.searchParams.set('t', bucket);

        return url.toString();
    } catch {
        // The backend only sends absolute URLs, so a malformed one is shown as it is rather than breaking the render.
        return stream.thumbnail_url;
    }
}
