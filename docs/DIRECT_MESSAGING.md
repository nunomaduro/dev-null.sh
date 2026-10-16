# Direct messaging

## Purpose and scope

Developers can continue a conversation privately without sharing personal contact details. The first release supports one-to-one text conversations and fits the existing Laravel actions, form requests, React/Inertia pages, and in-app notifications. There is no new service to operate.

## Privacy settings

Settings → Messages offers:

| Choice | New conversations | Existing accepted conversations |
| --- | --- | --- |
| Off (default) | Cannot send or receive | History remains readable; sending and receiving pause |
| People I follow | The recipient must follow the sender | Both people can continue while messaging is enabled |
| Everyone | Followed senders go straight to the inbox; others send one request | Both people can continue while messaging is enabled |

Following direction matters: following someone does not grant you permission to message them. Both accounts must enable messaging before starting or accepting a conversation. Read receipts default off and are visible only when both people enable them and the request has been accepted. Request readers are never exposed to the sender before acceptance.

A recipient can accept, decline, archive, mute, or block. A declined request stays unavailable to the sender, who continues to see a generic waiting state. It cannot be repeatedly re-sent. The recipient can reopen it from Archived. Existing user blocks apply in both directions, hide the conversation, and suppress notifications. Unblocking restores access to the existing history; it does not restore follows. Existing account mutes silence DM notifications; conversation mute can silence just one conversation.

## Experience

- Messages appears in desktop/mobile navigation with an unread-conversation count.
- Profile message buttons and New message open a recipient lookup by username.
- Inbox, Requests, and Archived are separate folders with username search and cursor pagination.
- Messages are plain text, up to 4,000 characters, rendered as text rather than HTML. Enter sends; Shift+Enter inserts a newline; IME composition does not send.
- History is paginated in batches of 50. Reading older history stops conversation polling. Latest messages returns to the live view.
- The active conversation polls every five seconds, inbox every ten, and the existing nav polling checks badges every thirty. Hidden tabs pause polling. These are polling intervals, not delivery guarantees or WebSocket transport.
- Sending disables duplicate submission and uses a client UUID for safe retries. The draft clears only after the server acknowledges that exact send. Validation failures retain the text.
- The message panel stays at its current position when reading older content. New arrivals have a jump-to-latest control. Read state advances only when the newest rendered message is visible in a focused tab.
- Archive is private to each participant. A new message brings the conversation back to Inbox. Mute remains in place.

## Notifications and security

The existing notifications table gains a nullable conversation reference and two event types: message request and message. There is one notification row per conversation per recipient, refreshed on new messages. No message body is copied into notifications. Clicking opens the conversation. Opening the general Notifications page does not mark message content read.

Membership is enforced on every conversation read/write endpoint, including cursor pages and read receipts. Nonparticipants and blocked participants get a 404. Sending, decisions, settings, and blocking use ordered user-row locks around request authorization and mutation. Database constraints enforce one conversation per unordered user pair and one message per sender/client UUID. Read positions move forward by message ID and never clear later arrivals.

Messages are encrypted at rest with Laravel's encrypted cast using the application key. This is **not end-to-end encryption**: the application can decrypt them. Preserve and back up application encryption keys when deploying; normal Laravel key-rotation requirements apply. Messages are excluded from public feeds, public API routes, link previews, and public user serialization. Private messaging responses use no-store and encrypted Inertia history. DMs do not enter public-post AI moderation or external link-unfurl services.

Limits: 30 send attempts per minute per sender; 10 new unsolicited requests per day. An unaccepted conversation allows only one message. Limits currently live alongside the project's existing named rate limiters.

Data exports move to schema version 3 and include messaging preferences and messages authored by the exporter; other participants' bodies are excluded. Deleting either account deletes the entire one-to-one conversation, messages, membership state, and associated notifications for both participants. Archive is not deletion. There is no per-message delete/unsend in this release.

## Validation and acceptance

Feature tests cover default-off settings, follow direction, request gating, acceptance/decline, settings changes, auth/IDOR, blocks, mutes, archive, counters, notification integration, encrypted storage, read-receipt consent, monotonic read watermarks, idempotency, body validation, history pagination, rate limits, exports, and account-deletion cleanup. Keep existing PHP, TypeScript, lint, type-coverage, and full coverage gates. Browser verification is manual; repository guidance excludes committed browser tests.

Acceptance: no private body reaches a nonparticipant, public payload, or notification; a blocked user cannot send; retries cannot duplicate a message; a late read acknowledgement cannot clear a newer message; reading a request reveals no receipt. Functional delivery is a two-person send/receive/reply flow with unread and notification updates.

## Recommended next PRs

1. **Reporting and support workflow:** report a selected message with explicit disclosure of what the reporter shares with moderators, retain a bounded evidence snapshot, and provide report status. This should come before widening discovery or raising unsolicited-request limits.
2. **Developer collaboration:** share a commit privately by reference, render safe fenced code, reply to a particular message, copy code, and add a small reaction set. Re-check visibility of shared commits for each recipient.
3. **Inbox polish:** pinned conversations, mark unread for personal follow-up, configurable notification previews, and persisted drafts with a clearly defined local retention/logout policy. Keep unread-for-follow-up separate from shared read receipts.
4. **Attachments:** authenticated private downloads, content limits, MIME validation, malware handling, storage cleanup, and no public bucket URLs. Introduce images/files only after these controls exist.
5. **Live transport:** private broadcast channels or SSE, authorized per participant, reconnect and event replay, bounded typing indicators, and user-controlled presence. Measure polling load first.
6. **Groups:** explicit invitations, roles, leave/remove behavior, history visibility for newly added members, and block handling. A separate design is needed; the current database intentionally enforces two participants.
7. **End-to-end encryption:** a separate security project covering multi-device keys, recovery, rotation, verification, attachment encryption, and user-initiated abuse reporting. Do not label application-key encryption as E2EE.

After launch, measure opt-in, request acceptance/decline, first replies, repeat conversations, block/report rates, send failure/latency, and poll query load. Establish a baseline before setting adoption targets. Never record message bodies in product analytics.

## X reference

The permission model borrows the useful parts of X's documented rules: people the recipient follows can initiate, unfamiliar senders use a separate request inbox when allowed, accepted conversations can continue, blocking stops contact, and requests do not reveal read receipts before acceptance. This implementation deliberately adds a true Off setting and defaults read receipts off. X's verified/subscription exceptions and encrypted Chat protocol are not copied.

Sources checked 2026-10-16:
- https://help.x.com/en/using-x/direct-messages
- https://help.x.com/en/using-x/about-chat
