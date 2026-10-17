export function localDateTime(date: Date): string {
    const pad = (part: number) => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function scheduledTimestamp(value: string): string | null {
    const date = new Date(value);

    if (Number.isNaN(date.getTime()) || localDateTime(date) !== value) {
        return null;
    }

    return date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}
