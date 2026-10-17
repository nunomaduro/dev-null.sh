import { http } from '@inertiajs/react';
import { refreshSharedState } from '@/hooks/use-shared-state';

const fields = [
    'starred',
    'stars_count',
    'pasteis_count',
    'pasteis_received',
    'stars_received',
    'is_saved',
    'replies_count',
    'commits_count',
] as const;

type CommitStateField = (typeof fields)[number];

export const commitStateKey = (field: CommitStateField, id: string): string =>
    `${field}:${id}`;

const parse = (data: string): unknown => {
    try {
        return JSON.parse(data);
    } catch {
        return null;
    }
};

function collect(value: unknown, fresh: Map<string, boolean | number>): void {
    if (Array.isArray(value)) {
        value.forEach((item) => collect(item, fresh));

        return;
    }

    if (typeof value !== 'object' || value === null) {
        return;
    }

    const record = value as Record<string, unknown>;

    if (typeof record.id === 'string') {
        for (const field of fields) {
            const fieldValue = record[field];

            if (
                typeof fieldValue === 'boolean' ||
                typeof fieldValue === 'number'
            ) {
                fresh.set(commitStateKey(field, record.id), fieldValue);
            }
        }
    }

    Object.values(record).forEach((item) => collect(item, fresh));
}

export function initializeCommitState(): void {
    http.onResponse((response) => {
        const fresh = new Map<string, boolean | number>();

        collect(parse(response.data), fresh);
        refreshSharedState(fresh);

        return response;
    });
}
