const compact = new Intl.NumberFormat('en-US', {
    notation: 'compact',
    maximumFractionDigits: 1,
    roundingMode: 'trunc',
});

const full = new Intl.NumberFormat('en-US');

/**
 * Keeps big counts short enough for a stats row: 999, 1.2K, 3.4M, 1B.
 * It truncates instead of rounding, so a count never reads higher than it is: 999,999 is 999.9K, not 1M.
 */
export function formatCompactCount(count: number): string {
    return compact.format(count);
}

/**
 * The exact count, for a title next to a compact one: 1,234,567.
 */
export function formatFullCount(count: number): string {
    return full.format(count);
}
