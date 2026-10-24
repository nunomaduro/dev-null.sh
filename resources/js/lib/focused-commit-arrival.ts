/**
 * How a page was reached: Inertia's navigate event type, with a fresh page load split by the browser's own navigation
 * type, since Inertia reports a reload and a back/forward load as "initial" too.
 */
export type Arrival = 'link' | 'reload' | 'history' | 'other';

export function arrivalOf(
    navigateType: string,
    loadType: string | undefined,
): Arrival {
    if (navigateType === 'visit') {
        return 'link';
    }

    if (navigateType === 'history') {
        return 'history';
    }

    if (navigateType === 'initial') {
        if (loadType === 'reload') {
            return 'reload';
        }

        return loadType === 'back_forward' ? 'history' : 'link';
    }

    return 'other';
}

/**
 * A commit with its thread drawn above it opens scrolled to the top, so arriving by a link (a notification, a reply,
 * a pasted URL) would leave it at the bottom of the screen. Only a new arrival is scrolled: going back and reloading
 * keep the position the browser restores, and a visit that keeps the page's state or scroll (a reload of props,
 * closing a photo) keeps the reader where they are.
 */
export function shouldScrollToFocusedCommit({
    component,
    arrival,
    keepsPlace,
    ancestorCount,
}: {
    component: string;
    arrival: Arrival;
    keepsPlace: boolean;
    ancestorCount: number;
}): boolean {
    return (
        component === 'commit/show' &&
        arrival === 'link' &&
        !keepsPlace &&
        ancestorCount > 0
    );
}
