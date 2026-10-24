/**
 * A row of a list as it shows: leaving once its item is gone from the list, until it has collapsed.
 */
export type ListRow<T> = { item: T; isLeaving: boolean };

/**
 * The rows for the next items, with each item that left kept where it was, after the row it followed, so it can
 * collapse in place instead of the rows under it jumping up.
 */
export function withLeavingRows<T extends { id: number }>(
    previous: ListRow<T>[],
    next: T[],
): ListRow<T>[] {
    const nextIds = new Set(next.map((item) => item.id));
    const rows = next.map((item) => ({ item, isLeaving: false }));
    let at = 0;

    previous.forEach((row) => {
        if (nextIds.has(row.item.id)) {
            at = rows.findIndex(({ item }) => item.id === row.item.id) + 1;

            return;
        }

        rows.splice(at, 0, { item: row.item, isLeaving: true });
        at++;
    });

    return rows;
}
