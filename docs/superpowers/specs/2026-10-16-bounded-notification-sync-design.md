# Bounded Notification Inbox Synchronization Design

## Status

Approved approach: fixed notification head plus visible-row revalidation.

This design replaces inbox polling whose request and response size grows with every loaded page. It is a separate change from unread-count query optimization, which is already covered by upstream pull request #264.

## Problem

The notification inbox polls every 30 seconds. The client sends the oldest loaded group timestamp as `through`, and the server expands the replacement page to every group at or above that timestamp. Loading more pages therefore makes each later poll perform more database work, serialize more rows, transfer a larger response, and reconcile a larger DOM-backed list.

The current replacement does provide useful behavior: the loaded window is exact, newly active old groups move to the top, removed groups disappear, the scroll anchor is preserved, and the deepest cursor remains usable. The replacement must keep those properties where bounded data can prove them and fail safely where it cannot.

## Goals

- Keep every polling request and response bounded, independent of pagination depth.
- Keep the newest 20 groups exact.
- Revalidate up to 20 groups currently visible in the viewport.
- Preserve already loaded offscreen history and its existing pagination cursor.
- Prevent duplicate groups when old conversations receive new activity.
- Preserve filtering, read acknowledgement, arrival indicators, and scroll anchoring.
- Fall back to a full page-one refresh when a bounded response cannot prove continuity.

## Non-goals

- Do not persist notification groups or add a change-feed table.
- Do not make every offscreen historical row immediately consistent.
- Do not immediately discover an old notification that becomes relevant without a new notification event, such as a previously muted actor becoming visible again. It appears after a normal page-one refresh.
- Do not immediately discover a notification that becomes visible after a sync snapshot but sorts below that snapshot's proven boundary, such as an insert committed from a delayed transaction. It also appears after a normal page-one refresh.
- Do not rewrite the full-history grouping query. Pull request #264 addresses that cost separately.
- Do not change group identity, filter semantics, or expanded-group history.
- Do not solve the expanded-group state issue covered by upstream pull request #246.

## HTTP Contract

Add an authenticated, private, non-cacheable `GET /notifications/sync` endpoint.

The request accepts:

- `type`: the existing notification filter, defaulting to `all`.
- `visible[]`: an optional list of distinct notification group UUIDs, limited to 20.

The JSON response is:

```json
{
  "head": [],
  "boundary": { "group_id": "uuid", "id": "uuid" },
  "visible": [],
  "missing_visible_group_ids": [],
  "unread_notifications_count": 0
}
```

`head` contains the newest 20 current groups. The server queries 21 groups and returns the 21st representative's stable group ID and latest event ID as `boundary`. A null boundary means the head contains the complete current inbox for the selected filter.

`visible` contains current representatives for requested visible groups that are not already present in `head`. `missing_visible_group_ids` contains requested IDs that no longer identify a current group. A changed group ID is treated as removal of the old visible row; its replacement can return through the head or a later full refresh.

Rows in `head` and `visible` use the existing serialized `NotificationGroup` shape. That includes the representative actor and commit, up to three stacked actors, `actor.is_followed`, `event_count`, `actors_count`, and `unread_count`; existing hidden query columns remain hidden.

The endpoint calls `UpdateNotificationsReadAt` before reading the result, matching current inbox-poll behavior. It then returns the real unread group count so activity created during the request is not incorrectly reported as read.

## Server Design

Add a dedicated sync controller and request object. The request validates the filter and UUID list before the query runs. Authentication comes from the existing notification route group, and every query is scoped to the current recipient.

Extend `NotificationGroupsQuery` with bounded operations that select representative identity in one database statement. Rank current representatives globally, then retain rows whose position is at most 21 or whose group ID was requested in `visible[]`. Split that result in application code so the head, boundary, and visible representatives always describe the same statement snapshot. The operation:

1. Read at most 21 ranked group representatives for the selected filter.
2. Load actors and commits for the 20 returned head rows.
3. Resolve at most 20 requested visible group IDs using the same grouping and filtering rules and the same representative snapshot.
4. Load actors and commits for visible rows not already included in the head.
5. Compute missing requested IDs from the returned head and visible IDs.

The grouping query still evaluates recipient history until the separate query optimization lands, but the number of selected models, related records, serialized rows, and response bytes no longer depends on loaded page count.

The continuity proof applies to the ordered rows visible in one database snapshot. Application notification writes use the current time and a new UUIDv7, so an ordinarily committed new event sorts ahead of unchanged historical representatives. Database visibility still follows commit order rather than event timestamp order: a delayed transaction can become visible after a snapshot while sorting below its boundary. That case, and state changes that reveal an older existing event, are deliberately covered by the historical-staleness non-goals and require a page-one refresh. A stronger guarantee would require a persistent recipient mutation version or change feed, which is outside this design.

Remove `through` from the normal list request and remove dynamic `perPage` expansion from `paginate()`. Normal page loads and infinite-scroll requests always use `NotificationGroupsQuery::PER_PAGE`.

## Client Polling

Replace the inbox's Inertia reload poll with the repository's existing bounded JSON polling pattern:

- Run every 30 seconds.
- Pause while the document is hidden.
- Never overlap requests.
- Abort on unmount or filter navigation.
- Discard a response if filter or page-one navigation changes before its queued client update executes.
- Use a 10-second timeout and `cache: 'no-store'`.
- On failure, keep the last good state and retry at the next interval.

Before each request, collect intersecting `[data-notification-group]` elements in DOM order and send at most 20 group IDs. The client records the current scroll anchor before applying a successful response.

## Reconciliation

Put reconciliation in a pure TypeScript function so ordering and fallback behavior can be tested without rendering the page.

Given the loaded list and a sync response:

1. Treat the response as complete when `boundary` is null. Use `head` as the whole list and set `next_cursor` to null. If Inertia's infinite-scroll manager still owns a non-null next-page token, perform an automatic page-one reset instead of only replacing the prop; this clears its separate internal cursor state.
2. Otherwise, continuity is proven when a loaded row matches both boundary fields: its stable `group_id` and latest representative event `id`. Matching only `group_id` is unsafe because new activity can bump a loaded group while retaining that ID.
3. An unchanged first page is also safe when the returned head representative pairs (`group_id` and latest event `id`) exactly match the loaded prefix. Comparing both IDs prevents a group with new activity from disguising an ambiguous burst merely because its stable `group_id` stayed in place. This avoids a false refresh when the 21st current group has never been loaded and nothing ahead of it changed.
4. If neither continuity condition holds, do not change notification data. Set a `refreshRequired` state instead.
5. For a safe response, start with the server head.
6. Append the existing loaded list after removing head duplicates and missing visible IDs.
7. Replace retained visible rows with their fresh server versions.
8. Deduplicate the final list by `group_id`, preserving the first occurrence.
9. Preserve the existing `next_cursor` unless the response is complete.

This makes the head and visible rows exact while deliberately leaving other retained rows in their previous order. A group bumped from the retained tail into the head appears only once.

Apply the reconciled paginator and unread count together through one functional Inertia client-side prop replacement. The updater must reconcile against Inertia's current paginator when the queued replacement executes, not the React prop captured when the poll completed. This preserves a page and deeper cursor that `InfiniteScroll` may merge while the poll result is waiting in the client queue. Run post-swap decisions after either Inertia completion branch for client replacements, page-one resets, and filter visits, because non-empty resulting error props make Inertia use its error callback even when the swap succeeds. The existing layout effect then restores the anchor and derives the arrival count from the anchor's new position.

## Ambiguous Change Fallback

When continuity cannot be proven, show a `New notifications` control without applying a partial result. Clicking it performs a normal page-one Inertia reload, resets the notification prop, clears retained history, and scrolls to the top. This prevents silent gaps after bursts or deletions that move the event boundary outside the loaded window.

Normal bounded arrivals continue to use the existing numeric arrival control.

## Deployment Compatibility

The client and server ship together with a changed asset manifest. Existing Inertia version handling reloads an old page before it can continue issuing the removed `through` contract against the new application version. The new sync endpoint is additive and is used only by the new client bundle.

No database migration or persistent backfill is required.

## Testing

Feature tests must prove:

- The sync head is 20 rows and the response exposes only the 21st representative pair with at least 62 groups present.
- Payload row counts remain capped regardless of how many pages the client previously loaded.
- Requested visible groups are refreshed, head duplicates are omitted, and missing IDs return as tombstones.
- A new event moves an old group into the head without changing its `group_id`.
- Filters use the same global group identity and read acknowledgement semantics as the inbox.
- Other recipients, irrelevant notifications, invalid filters, duplicate UUIDs, and lists over 20 are rejected or excluded as appropriate.
- Unauthenticated requests are rejected, and successful responses carry `Cache-Control: private, no-store`.
- Head and visible rows preserve current serialization, actor-stack, follow-state, and hidden-field behavior.
- Activity inserted after read acknowledgement remains unread in the returned global count.
- Head, boundary, and visible representatives remain from one statement snapshot when a requested tail group changes during synchronization.
- An old Inertia asset version triggers the existing reload path instead of continuing the removed `through` contract.
- The normal paginator no longer expands from a `through` value.

A directly discovered TypeScript unit suite must prove:

- Safe head replacement with a retained multi-page tail.
- A bumped old group is deduplicated.
- Visible replacements and tombstones are applied.
- A null boundary clears stale tail data and the cursor.
- A null boundary with an active infinite-scroll cursor requests a server-backed page-one reset; a list already known to be complete can update locally.
- A page merged by infinite scroll after a poll starts remains loaded with its deeper cursor when that poll is applied.
- A known boundary preserves the existing cursor.
- An unchanged first-page representative prefix is accepted, while matching group IDs with changed representative IDs are not treated as proof of continuity.
- A mixed burst of new groups and a bumped loaded group cannot use the bumped group as a false continuity boundary.
- An unknown boundary leaves data untouched and requests a full refresh.

Run the repository's focused tests, complete unit suite, lint, static analysis, type checks, production build, and structural change gates before submission.

## Acceptance Criteria

- Poll response size has a hard maximum of 20 head rows plus 20 non-head visible rows and one boundary representative pair.
- Loading additional pages does not change the sync request limit or response limit.
- The loaded list never contains duplicate `group_id` values after synchronization.
- The deepest pagination cursor remains unchanged during safe partial reconciliation.
- A concurrent infinite-scroll merge cannot be overwritten by a poll response based on an older React render.
- Within a sync snapshot, reconciliation never accepts a changed boundary representative or omits a group returned in the bounded head.
- A complete response cannot leave Inertia's infinite-scroll manager with a stale next-page token.
- No production behavior outside notification synchronization changes.
