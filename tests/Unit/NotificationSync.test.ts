import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    reconcileNotificationSync,
    visibleNotificationGroupIds,
} from '../../resources/js/lib/notification-sync.ts';
import type {
    CursorPaginated,
    NotificationGroup,
    NotificationSyncResponse,
} from '../../resources/js/types/index.ts';

const group = (groupId: string, id = groupId): NotificationGroup =>
    ({ group_id: groupId, id }) as NotificationGroup;

const sequence = (prefix: string, count: number): NotificationGroup[] =>
    Array.from({ length: count }, (_, index) => group(`${prefix}${index + 1}`));

const page = (
    data: NotificationGroup[],
    nextCursor: string | null = 'deep-cursor',
): CursorPaginated<NotificationGroup> => ({
    data,
    next_cursor: nextCursor,
    prev_cursor: null,
});

const response = (
    values: Partial<NotificationSyncResponse>,
): NotificationSyncResponse => ({
    head: [],
    boundary: null,
    visible: [],
    missing_visible_group_ids: [],
    unread_notifications_count: 0,
    ...values,
});

void test('replaces a twenty-row head and retains the loaded tail and cursor', () => {
    const loaded = sequence('A', 59);
    const head = [group('X'), ...loaded.slice(0, 19)];
    const result = reconcileNotificationSync(
        page(loaded),
        response({ head, boundary: group('A20') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.deepEqual(
        result.notifications.data.map(({ group_id }) => group_id),
        ['X', ...loaded.map(({ group_id }) => group_id)],
    );
    assert.equal(result.notifications.data.length, 60);
    assert.equal(result.notifications.next_cursor, 'deep-cursor');
});

void test('preserves a page merged after the poll request started', () => {
    const atRequestStart = sequence('A', 20);
    const currentAtApply = page(
        [...atRequestStart, ...sequence('merged-', 20)],
        'new-deep-cursor',
    );
    const head = [group('X'), ...atRequestStart.slice(0, 19)];
    const result = reconcileNotificationSync(
        currentAtApply,
        response({ head, boundary: group('A20') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, false);
    assert.equal(result.notifications.data.length, 41);
    assert.equal(result.notifications.data.at(-1)?.group_id, 'merged-20');
    assert.equal(result.notifications.next_cursor, 'new-deep-cursor');
});

void test('deduplicates a bumped old group by stable group id', () => {
    const loaded = sequence('A', 30);
    const bumped = group('A30', 'A30:new');
    const result = reconcileNotificationSync(
        page(loaded),
        response({
            head: [bumped, ...loaded.slice(0, 19)],
            boundary: group('A20'),
        }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.notifications.data[0], bumped);
    assert.equal(
        result.notifications.data.filter(({ group_id }) => group_id === 'A30')
            .length,
        1,
    );
});

void test('applies visible replacements and missing-row tombstones', () => {
    const head = sequence('A', 20);
    const current = page([...head, group('A21'), group('visible'), group('missing')]);
    const replacement = group('visible', 'visible:new');
    const result = reconcileNotificationSync(
        current,
        response({
            head,
            boundary: group('A21'),
            visible: [replacement],
            missing_visible_group_ids: ['missing'],
        }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(
        result.notifications.data.find(({ group_id }) => group_id === 'visible'),
        replacement,
    );
    assert.equal(
        result.notifications.data.some(({ group_id }) => group_id === 'missing'),
        false,
    );
});

void test('treats a null boundary as complete and clears retained history and cursor', () => {
    const head = sequence('A', 4);
    const result = reconcileNotificationSync(
        page([...head, ...sequence('stale', 5)]),
        response({ head }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, true);
    assert.deepEqual(result.notifications.data, head);
    assert.equal(result.notifications.next_cursor, null);
});

void test('updates a complete paginator locally when its cursor is already exhausted', () => {
    const head = sequence('A', 4);
    const result = reconcileNotificationSync(
        page([...head, ...sequence('stale', 5)], null),
        response({ head }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, false);
    assert.deepEqual(result.notifications.data, head);
    assert.equal(result.notifications.next_cursor, null);
});

void test('resets a live scroll cursor after error-bearing final-page props look exhausted', () => {
    const current = page(sequence('loaded', 40), null);
    const result = reconcileNotificationSync(
        current,
        response({ head: sequence('remaining', 4) }),
        false,
        true,
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, true);
});

void test('does not repeatedly reset a complete first page with an exhausted scroll manager', () => {
    const complete = response({ head: sequence('A', 4) });
    let current = page(complete.head, null);

    for (let poll = 0; poll < 2; poll++) {
        const result = reconcileNotificationSync(
            current,
            complete,
            false,
            false,
        );

        assert.equal(result.refreshRequired, false);
        assert.equal(result.paginationResetRequired, false);
        current = result.notifications;
    }
});

void test('accepts an unchanged representative prefix with an unloaded boundary', () => {
    const head = sequence('A', 20);
    const result = reconcileNotificationSync(
        page(head),
        response({ head: [...head], boundary: group('A21') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.notifications.next_cursor, 'deep-cursor');
});

void test('rejects a matching group-id prefix when a representative id changed', () => {
    const loaded = sequence('A', 20);
    const changed = [group('A1', 'A1:new'), ...loaded.slice(1)];
    const result = reconcileNotificationSync(
        page(loaded),
        response({ head: changed, boundary: group('A21') }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications.data, loaded);
});

void test('rejects a mixed burst whose stable boundary group has a new representative', () => {
    const current = page(sequence('A', 40));
    const burst = Array.from({ length: 20 }, (_, index) => group(`X${21 - index}`));
    const result = reconcileNotificationSync(
        current,
        response({
            head: burst,
            boundary: group('A30', 'A30:new'),
        }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications, current);
});

void test('returns the original paginator when no continuity proof exists', () => {
    const current = page(sequence('A', 20));
    const result = reconcileNotificationSync(
        current,
        response({ head: sequence('X', 20), boundary: group('unknown') }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications, current);
});

void test('keeps fallback sticky after ambiguous A even when queued B would be safe', () => {
    const current = page(sequence('A', 20));
    const ambiguous = reconcileNotificationSync(
        current,
        response({ head: sequence('X', 20), boundary: group('unknown') }),
    );
    const safe = response({ head: [group('replacement')] });

    assert.equal(ambiguous.refreshRequired, true);
    assert.notEqual(
        reconcileNotificationSync(current, safe).notifications,
        current,
    );

    const queued = reconcileNotificationSync(
        ambiguous.notifications,
        safe,
        ambiguous.refreshRequired,
    );

    assert.equal(queued.notifications, current);
    assert.equal(queued.notifications.data, current.data);
    assert.equal(queued.notifications.next_cursor, 'deep-cursor');
    assert.equal(queued.refreshRequired, true);
    assert.equal(queued.paginationResetRequired, false);
});

void test('collects distinct visible rows below the header and stops at twenty', () => {
    const element = (
        id: string,
        bounds: Partial<DOMRect> = {},
    ): HTMLElement =>
        ({
            dataset: { notificationGroup: id },
            getBoundingClientRect: () => ({
                width: 10,
                height: 10,
                top: 30,
                bottom: 40,
                left: 0,
                right: 10,
                ...bounds,
            }),
        }) as unknown as HTMLElement;
    const visible = Array.from({ length: 22 }, (_, index) =>
        element(`visible-${index + 1}`),
    );

    const ids = visibleNotificationGroupIds(
        [
            element('zero-size', { width: 0 }),
            element('under-header', { top: 0, bottom: 10 }),
            element('offscreen', { top: 110, bottom: 120 }),
            visible[0],
            ...visible,
        ],
        20,
        { innerHeight: 100, innerWidth: 100 },
    );

    assert.deepEqual(
        ids,
        Array.from({ length: 20 }, (_, index) => `visible-${index + 1}`),
    );
});
