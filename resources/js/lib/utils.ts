import type { InertiaLinkProps } from '@inertiajs/react';
import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { flushSync } from 'react-dom';
import { twMerge } from 'tailwind-merge';
import type { DeletedCommit, Lane, ThreadCommit } from '@/types';
import type { FlashToast } from '@/types/ui';

export function characterCount(value: string): number {
    return Array.from(value).length;
}

export function limitCharacters(value: string, max: number): string {
    return Array.from(value).slice(0, max).join('');
}

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

export function formatRelativeTime(date: string, now: Date = new Date()) {
    const seconds = Math.round(
        (now.getTime() - new Date(date).getTime()) / 1000,
    );

    if (seconds < 60) {
        return 'now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    if (seconds < 604800) {
        return `${Math.floor(seconds / 86400)}d`;
    }

    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year:
            new Date(date).getFullYear() === now.getFullYear()
                ? undefined
                : 'numeric',
    });
}

export function formatFullDateTime(date: string, timeZone?: string): string {
    const value = new Date(date);

    return `${value.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    })} · ${value.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone,
    })}`;
}

export function laneColor(lane: Lane): string {
    return typeof lane === 'string' ? lane : `var(--lane-${lane})`;
}

export function isDeleted(commit: {
    deleted_at?: string | null;
}): commit is DeletedCommit {
    return typeof commit.deleted_at === 'string';
}

/**
 * Whether a commit submission came back without a commit. The `committed` and `scheduled` flashes can't tell:
 * they live in the session, so a poll or another tab landing before the redirect takes them. The rejections
 * that still redirect back (rate limited, page expired) always flash an error toast instead.
 */
export function isRejectedSubmission(flash: { toast?: FlashToast }): boolean {
    return flash.toast?.type === 'error';
}

export function laneOf(commit: ThreadCommit): string {
    return isDeleted(commit) ? 'var(--rail)' : laneColor(commit.user.lane);
}

export function shortHash(id: string): string {
    return id.replaceAll('-', '').slice(-7);
}

export function isWithinSeconds(
    date: string,
    seconds: number,
    now: Date = new Date(),
) {
    return now.getTime() - new Date(date).getTime() < seconds * 1000;
}

export function localDayKey(date: string): string {
    const day = new Date(date);

    return `${day.getFullYear()}-${day.getMonth() + 1}-${day.getDate()}`;
}

export function formatDayLabel(date: string, now: Date = new Date()): string {
    const day = new Date(date);
    const startOfToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    );
    const startOfDay = new Date(
        day.getFullYear(),
        day.getMonth(),
        day.getDate(),
    );
    const daysAgo = Math.round(
        (startOfToday.getTime() - startOfDay.getTime()) / 86_400_000,
    );

    if (daysAgo === 0) {
        return 'Today';
    }

    if (daysAgo === 1) {
        return 'Yesterday';
    }

    return day.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: day.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    });
}

export function browserTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

export function formatZonedTime(date: string, timeZone: string): string {
    return new Date(date).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

export function formatZonedDate(date: string, timeZone: string): string {
    const value = new Date(date);

    return value.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year:
            value.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
        timeZone,
    });
}

export function timeZoneCity(timeZone: string): string {
    return timeZone.slice(timeZone.lastIndexOf('/') + 1).replaceAll('_', ' ');
}

/**
 * Stops the content under the tabs from sliding in the next view transition.
 */
export function clearTabSlide(): void {
    delete document.documentElement.dataset.tabSlide;
}

let currentTransition: ViewTransition | undefined;

/**
 * Applies a state change inside a view transition, so the page animates to it like it does between pages. The `kind`
 * is set on the root element while the transition runs: the `in-place:` variant names the parts that move on their
 * own only then, so they still slide with their tab between pages.
 */
export function withViewTransition(
    update: () => void,
    kind: 'in-place' | 'theme' = 'in-place',
): Promise<void> {
    if (typeof document.startViewTransition !== 'function') {
        update();

        return Promise.resolve();
    }

    clearTabSlide();
    document.documentElement.dataset.viewTransition = kind;

    const transition = document.startViewTransition(() => flushSync(update));
    currentTransition = transition;

    return transition.finished
        .catch(() => undefined)
        .finally(() => {
            // A newer transition skips this one: leave its kind in place...
            if (currentTransition === transition) {
                currentTransition = undefined;
                delete document.documentElement.dataset.viewTransition;
            }
        });
}
