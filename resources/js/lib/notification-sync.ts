import type {
    CursorPaginated,
    NotificationGroup,
    NotificationSyncRepresentative,
    NotificationSyncResponse,
} from '@/types';

const maxVisibleGroups = 20;

const sameRepresentative = (
    left: NotificationSyncRepresentative,
    right: NotificationSyncRepresentative,
): boolean => left.group_id === right.group_id && left.id === right.id;

const uniqueGroups = (groups: NotificationGroup[]): NotificationGroup[] => {
    const seen = new Set<string>();

    return groups.filter(({ group_id }) => {
        if (seen.has(group_id)) {
            return false;
        }

        seen.add(group_id);

        return true;
    });
};

export function visibleNotificationGroupIds(
    elements: Iterable<HTMLElement>,
    headerBottom: number,
    viewport: Pick<Window, 'innerHeight' | 'innerWidth'>,
): string[] {
    const ids: string[] = [];
    const seen = new Set<string>();

    for (const element of elements) {
        const id = element.dataset.notificationGroup ?? '';
        const bounds = element.getBoundingClientRect();
        const visible =
            id !== '' &&
            bounds.width > 0 &&
            bounds.height > 0 &&
            bounds.bottom > headerBottom &&
            bounds.top < viewport.innerHeight &&
            bounds.right > 0 &&
            bounds.left < viewport.innerWidth;

        if (!visible || seen.has(id)) {
            continue;
        }

        seen.add(id);
        ids.push(id);

        if (ids.length === maxVisibleGroups) {
            break;
        }
    }

    return ids;
}

export function reconcileNotificationSync(
    current: CursorPaginated<NotificationGroup>,
    sync: NotificationSyncResponse,
    refreshRequired = false,
    scrollHasNext = false,
): {
    notifications: CursorPaginated<NotificationGroup>;
    refreshRequired: boolean;
    paginationResetRequired: boolean;
} {
    if (refreshRequired) {
        return {
            notifications: current,
            refreshRequired: true,
            paginationResetRequired: false,
        };
    }

    const boundary = sync.boundary;

    if (boundary === null) {
        return {
            notifications: {
                ...current,
                data: uniqueGroups(sync.head),
                next_cursor: null,
            },
            refreshRequired: false,
            paginationResetRequired:
                current.next_cursor !== null || scrollHasNext,
        };
    }

    const knownBoundary = current.data.some((group) =>
        sameRepresentative(group, boundary),
    );
    const unchangedPrefix =
        sync.head.length > 0 &&
        sync.head.every((group, index) => {
            const loaded = current.data[index];

            return loaded !== undefined && sameRepresentative(group, loaded);
        });

    if (!knownBoundary && !unchangedPrefix) {
        return {
            notifications: current,
            refreshRequired: true,
            paginationResetRequired: false,
        };
    }

    const headIds = new Set(sync.head.map(({ group_id }) => group_id));
    const missingIds = new Set(sync.missing_visible_group_ids);
    const visible = new Map(
        sync.visible.map((group) => [group.group_id, group]),
    );
    const retained = current.data
        .filter(
            ({ group_id }) =>
                !headIds.has(group_id) && !missingIds.has(group_id),
        )
        .map((group) => visible.get(group.group_id) ?? group);

    return {
        notifications: {
            ...current,
            data: uniqueGroups([...sync.head, ...retained]),
        },
        refreshRequired: false,
        paginationResetRequired: false,
    };
}
