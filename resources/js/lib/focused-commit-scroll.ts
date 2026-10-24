import { router } from '@inertiajs/react';
import {
    arrivalOf,
    shouldScrollToFocusedCommit,
} from '@/lib/focused-commit-arrival';

// Inertia fires the first navigate event after the first page is laid out, so that page scrolls itself.
let firstPageLoaded = false;

/**
 * Brings the focused commit into view when its page is opened, under the thread it replies to, before the page is
 * painted where possible, so the reader lands on it instead of watching the thread scroll by. Inertia scrolls a
 * visited page to the top right before its navigate event, so a visit is scrolled then.
 */
export function trackFocusedCommitScroll(): VoidFunction {
    const placeKeepingVisits = new Set<string>();

    const stopStart = router.on('start', ({ detail: { visit } }) => {
        if (visit.preserveState || visit.preserveScroll) {
            placeKeepingVisits.add(visit.id);
        }
    });

    const stopNavigate = router.on(
        'navigate',
        ({ detail: { page, type, visitId } }) => {
            const keepsPlace =
                visitId !== undefined && placeKeepingVisits.delete(visitId);
            const ancestors = page.props.ancestors as unknown[] | undefined;
            const commit = page.props.commit as { id: string } | undefined;

            firstPageLoaded = true;

            if (
                type !== 'initial' &&
                commit !== undefined &&
                shouldScrollToFocusedCommit({
                    component: page.component,
                    arrival: arrivalOf(type, undefined),
                    keepsPlace,
                    ancestorCount: ancestors?.length ?? 0,
                })
            ) {
                scrollToCommit(commit.id);
            }
        },
    );

    return () => {
        stopStart();
        stopNavigate();
    };
}

/**
 * Called by the commit page once it is laid out: scrolls the first page of a load to its focused commit.
 */
export function scrollFirstPageToFocusedCommit(
    id: string,
    ancestorCount: number,
): void {
    if (firstPageLoaded) {
        return;
    }

    firstPageLoaded = true;

    const load = performance.getEntriesByType('navigation')[0] as
        | PerformanceNavigationTiming
        | undefined;

    if (
        shouldScrollToFocusedCommit({
            component: 'commit/show',
            arrival: arrivalOf('initial', load?.type),
            keepsPlace: false,
            ancestorCount,
        })
    ) {
        scrollToCommit(id);
    }
}

function scrollToCommit(id: string): void {
    // The card's scroll margin keeps it clear of the sticky header. Never smooth: the reader lands on it.
    document
        .querySelector<HTMLElement>(`[data-commit="${CSS.escape(id)}"]`)
        ?.scrollIntoView({ block: 'start', behavior: 'instant' });
}
