import { cn } from '@/lib/utils';
import type { LivePlatform } from '@/types';

/**
 * The provider's mark at text size, decorative (`aria-hidden`), so pair it with the platform's name somewhere.
 */
export function PlatformGlyph({
    platform,
    className,
}: {
    platform: LivePlatform;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
            className={cn('size-[1em] shrink-0', className)}
            data-test="platform-glyph"
            data-platform={platform}
        >
            {platform === 'youtube' ? (
                <>
                    <rect x="2.5" y="5" width="19" height="14" rx="4" />
                    <path d="M10 9.5v5l4.5-2.5z" fill="currentColor" />
                </>
            ) : (
                <>
                    <path d="M4 3h16v11l-4 4h-4l-3 3v-3H4z" />
                    <path d="M11 7.5v4M15.5 7.5v4" />
                </>
            )}
        </svg>
    );
}
