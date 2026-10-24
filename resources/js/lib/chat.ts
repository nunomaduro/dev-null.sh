import { router } from '@inertiajs/react';
import BannedController from '@/actions/App/Http/Controllers/BannedController';
import ConversationArchiveController from '@/actions/App/Http/Controllers/ConversationArchiveController';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import ConversationDropController from '@/actions/App/Http/Controllers/ConversationDropController';
import ConversationMergeController from '@/actions/App/Http/Controllers/ConversationMergeController';
import ConversationMessageController from '@/actions/App/Http/Controllers/ConversationMessageController';
import ConversationMuteController from '@/actions/App/Http/Controllers/ConversationMuteController';
import ConversationPinController from '@/actions/App/Http/Controllers/ConversationPinController';
import ConversationReadController from '@/actions/App/Http/Controllers/ConversationReadController';
import ConversationTypingController from '@/actions/App/Http/Controllers/ConversationTypingController';
import InboxController from '@/actions/App/Http/Controllers/InboxController';
import MessagePinController from '@/actions/App/Http/Controllers/MessagePinController';
import MessageReactionController from '@/actions/App/Http/Controllers/MessageReactionController';
import OrgArchiveController from '@/actions/App/Http/Controllers/OrgArchiveController';
import OrgController from '@/actions/App/Http/Controllers/OrgController';
import OrgMemberController from '@/actions/App/Http/Controllers/OrgMemberController';
import OrgMessageController from '@/actions/App/Http/Controllers/OrgMessageController';
import OrgMuteController from '@/actions/App/Http/Controllers/OrgMuteController';
import OrgPinController from '@/actions/App/Http/Controllers/OrgPinController';
import OrgReadController from '@/actions/App/Http/Controllers/OrgReadController';
import OrgTypingController from '@/actions/App/Http/Controllers/OrgTypingController';
import RemoteCandidateController from '@/actions/App/Http/Controllers/RemoteCandidateController';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import { showTimeout, timeoutFrom } from '@/components/timeout-dialog';
import {
    acceptedPins,
    reactionsAfterOf,
    withPin,
    withPinRestored,
    withoutPin,
    withShownReactions,
} from '@/lib/pins';
import type { Pins } from '@/lib/pins';
import type { ReactionUsage } from '@/lib/reactions';
import { withReactionUse } from '@/lib/reactions';
import {
    createRemoteAlerts,
    isRemoteMuted,
    remoteAlertsEnabled,
} from '@/lib/remote-notifications';
import type { VoiceDraft } from '@/lib/voice-notes';
import type {
    ConversationSummary,
    InboxPage,
    InboxTab,
    Message,
    MessageReaction,
    MessageReactionChange,
    MessageReply,
    OrgMember,
    OrgRef,
    OrgRole,
    OrgThread,
    PushAccess,
    Thread,
    User,
} from '@/types';

/**
 * Who a conversation is with: one person, or an org.
 */
export type ChatTarget =
    | { kind: 'direct'; partner: User }
    | { kind: 'org'; org: OrgRef };

/**
 * What the composer pushes: the body, the message it replies to, its images, the link chosen for its card, which
 * is the first link when undefined, or none when null, and a voice note, which goes without a body or images.
 */
export type MessageDraft = {
    body: string;
    replyTo: MessageReply | null;
    images: File[];
    descriptions: string[];
    linkPreviewUrl: string | null | undefined;
    voice?: VoiceDraft | null;
};

export type PendingMessage = {
    client_id: string;
    body: string;
    reply_to: MessageReply | null;
    images: File[];
    image_descriptions: string[];
    link_preview_url: string | null | undefined;
    voice: VoiceDraft | null;
    created_at: string;
    status: 'staged' | 'rejected';
};

export type ThreadState = {
    target: ChatTarget;
    messages: Message[];
    has_older: boolean;
    read_through_id: number;
    partner_read_through_id: number;
    partner_read_at: string | null;
    partner_typing: boolean;
    members: OrgMember[];
    typing: string[];
    role: OrgRole | null;
    /**
     * The org's pinned messages, latest pinned first, which everyone in the org sees whatever history they were given.
     */
    pinned: Message[];
    /**
     * The reactions version the pinned messages are current with, which only moves when a list of them is taken.
     */
    pinnedVersion: number | null;
    is_archived: boolean;
    pinned_at: string | null;
    muted_until_at: string | null;
    access: PushAccess;
    pending: PendingMessage[];
    /**
     * The conversation's reactions version the thread is current with, which polls ask for the changes after. Null
     * until the server has handed one over.
     */
    reactions_version: number | null;
    /**
     * The message the composer is replying to, kept while the user moves between the dock and Remotes.
     */
    replyingTo: MessageReply | null;
    /**
     * Where the user had read up to when they opened the conversation, kept while it stays open.
     */
    head: number;
    /**
     * The latest message when the conversation was opened: replies arriving while it is open are not behind HEAD.
     */
    headUntil: number;
    isLoaded: boolean;
    isLoadingOlder: boolean;
};

export type ChatState = {
    conversations: ConversationSummary[] | null;
    fetched: ConversationSummary[] | null;
    archived: ConversationSummary[] | null;
    hasMore: Record<InboxTab, boolean>;
    isLoadingMore: Record<InboxTab, boolean>;
    unread: number | null;
    requests: number | null;
    isPollingInbox: boolean;
    notificationTitle: string | null;
    threads: Record<string, ThreadState>;
    mutePreferences: Record<string, { until: string | null; version: number }>;
    pinPreferences: Record<string, { at: string | null; version: number }>;
    /**
     * The emoji the user reacts with, most used first, loaded once something shows a conversation.
     */
    reactionUsage: ReactionUsage[] | null;
};

export type Candidate = { user: User; reason: string | null };

const activePoll = 1_500;
const idlePoll = 4_000;
const inboxPoll = 15_000;
const busyInboxPoll = 6_000;
const activeWindow = 60_000;
const typingPing = 3_000;

const listOf = {
    open: 'conversations',
    fetched: 'fetched',
    stashed: 'archived',
} as const;

let state: ChatState = {
    conversations: null,
    fetched: null,
    archived: null,
    hasMore: { open: false, fetched: false, stashed: false },
    isLoadingMore: { open: false, fetched: false, stashed: false },
    unread: null,
    requests: null,
    isPollingInbox: false,
    notificationTitle: null,
    threads: {},
    mutePreferences: {},
    pinPreferences: {},
    reactionUsage: null,
};
const listeners = new Set<() => void>();
const threadWatchers = new Map<string, number>();
const lastActivity = new Map<string, number>();
const lastTypingPing = new Map<string, number>();
const reactionsInFlight = new Map<number, number>();
const reactionsSettledAt = new Map<number, number>();
const pinsInFlight = new Map<string, number>();
/**
 * The newest pinned messages a pin request handed back while others in the org were still in flight.
 */
const newestPins = new Map<
    string,
    { pinned: Message[]; version: number; sentAt: number }
>();
let inboxWatchers = 0;
let inboxTimer: number | undefined;
let isLoadingReactionUsage = false;
let remoteAlerts: ReturnType<typeof createRemoteAlerts> | null = null;
let isFetchingInbox = false;
let muteVersion = 0;
let pinVersion = 0;
let inboxRevision = 0;
const invalidatedInboxTabs = new Set<InboxTab>();

export function targetKey(target: ChatTarget): string {
    return target.kind === 'direct'
        ? target.partner.username
        : `org:${target.org.id}`;
}

export function summaryKey(summary: ConversationSummary): string {
    return summary.kind === 'org' || summary.partner === null
        ? `org:${summary.id}`
        : summary.partner.username;
}

export function targetOf(summary: ConversationSummary): ChatTarget {
    return summary.kind === 'org' || summary.partner === null
        ? { kind: 'org', org: { id: summary.id, name: summary.name } }
        : { kind: 'direct', partner: summary.partner };
}

function urls(target: ChatTarget) {
    if (target.kind === 'direct') {
        const partner = { partner: target.partner.username };

        return {
            show: ConversationController.show.url(partner),
            index: (query?: Record<string, number>) =>
                ConversationMessageController.index.url(
                    partner,
                    query ? { query } : undefined,
                ),
            store: ConversationMessageController.store.url(partner),
            read: ConversationReadController.update.url(partner),
            typing: ConversationTypingController.update.url(partner),
            stash: ConversationArchiveController.store.url(partner),
            pop: ConversationArchiveController.destroy.url(partner),
            mute: ConversationMuteController.update.url(partner),
            pin: ConversationPinController.update.url(partner),
        };
    }

    const org = { org: target.org.id };

    return {
        show: OrgController.show.url(org),
        index: (query?: Record<string, number>) =>
            OrgMessageController.index.url(org, query ? { query } : undefined),
        store: OrgMessageController.store.url(org),
        read: OrgReadController.update.url(org),
        typing: OrgTypingController.update.url(org),
        stash: OrgArchiveController.store.url(org),
        pop: OrgArchiveController.destroy.url(org),
        mute: OrgMuteController.update.url(org),
        pin: OrgPinController.update.url(org),
    };
}

export function subscribeToChat(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function chatSnapshot(): ChatState {
    return state;
}

function update(next: Partial<ChatState>): void {
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

/**
 * Pages seed the store while rendering, so the change reaches other subscribers just after, not during, render.
 */
function updateDuringRender(next: Partial<ChatState>): void {
    state = { ...state, ...next };
    queueMicrotask(() => listeners.forEach((listener) => listener()));
}

/**
 * Inbox, search, and thread responses share mute state. A slower, older request must not undo a newer read or save.
 */
function withMutePreferences(
    preferences: [string, string | null][],
    version = ++muteVersion,
): ChatState['mutePreferences'] {
    const next = { ...state.mutePreferences };
    for (const [key, until] of preferences) {
        if (version >= (next[key]?.version ?? 0)) {
            next[key] = { until, version };
        }
    }
    return next;
}

function withPinPreferences(
    preferences: [string, string | null][],
    version = ++pinVersion,
): ChatState['pinPreferences'] {
    const next = { ...state.pinPreferences };
    for (const [key, at] of preferences) {
        if (version >= (next[key]?.version ?? 0)) {
            next[key] = { at, version };
        }
    }
    return next;
}

export function compareConversations(
    a: ConversationSummary,
    b: ConversationSummary,
): number {
    return (
        Number(b.pinned_at != null) - Number(a.pinned_at != null) ||
        (b.last_message_at ?? '').localeCompare(a.last_message_at ?? '') ||
        b.id.localeCompare(a.id)
    );
}

function withConversationPins(
    items: ConversationSummary[],
): ConversationSummary[] {
    return items
        .map((item) => {
            const preference = state.pinPreferences[summaryKey(item)];
            return preference === undefined
                ? item
                : { ...item, pinned_at: preference.at };
        })
        .sort(compareConversations);
}

function updateThread(
    key: string,
    change: (thread: ThreadState) => Partial<ThreadState>,
): void {
    const thread = state.threads[key];

    if (thread === undefined) {
        return;
    }

    update({
        threads: {
            ...state.threads,
            [key]: { ...thread, ...change(thread) },
        },
    });
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class ChatRequestError extends Error {
    constructor(
        public status: number,
        public body: { message?: string; errors?: Record<string, string[]> },
    ) {
        super(body.message ?? `Chat request failed: ${status}`);
    }

    /**
     * The first reason the server gave, for showing next to whatever was refused.
     */
    firstError(): string {
        return Object.values(this.body.errors ?? {})[0]?.[0] ?? this.message;
    }
}

/**
 * Sends JSON, or a form when there are files in it, which gets longer to upload.
 */
async function request<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' = 'GET',
    body?: object | FormData,
): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        ...(body === undefined
            ? {}
            : { body: isForm ? body : JSON.stringify(body) }),
        cache: 'no-store',
        credentials: 'same-origin',
        signal: AbortSignal.timeout(isForm ? 60_000 : 10_000),
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as {
            message?: string;
            banned?: boolean;
        };
        const timeout = timeoutFrom(response.status, body);

        // A timeout or a ban stops every remote the same way, so the user is told wherever they tried...
        if (timeout) {
            showTimeout(timeout);
        } else if (response.status === 403 && body.banned === true) {
            router.visit(BannedController.show());
        }

        throw new ChatRequestError(response.status, body);
    }

    return (response.status === 204 ? null : await response.json()) as T;
}

function mergeMessages(current: Message[], incoming: Message[]): Message[] {
    if (incoming.length === 0) {
        return current;
    }

    const byId = new Map(current.map((message) => [message.id, message]));
    incoming.forEach((message) => byId.set(message.id, message));

    return [...byId.values()].sort((a, b) => a.id - b.id);
}

/**
 * Swaps in the reactions that changed, except on messages the user is reacting to right now, or reacted to after the
 * poll was sent: their own request hands back fresher reactions.
 */
function withReactionChanges(
    messages: Message[],
    changes: MessageReactionChange[],
    polledAt: number,
): Message[] {
    const changed = new Map(
        changes
            .filter(
                (change) =>
                    !reactionsInFlight.has(change.id) &&
                    (reactionsSettledAt.get(change.id) ?? 0) < polledAt,
            )
            .map((change) => [change.id, change.reactions]),
    );

    if (!messages.some((message) => changed.has(message.id))) {
        return messages;
    }

    return messages.map((message) =>
        changed.has(message.id)
            ? { ...message, reactions: changed.get(message.id) }
            : message,
    );
}

/**
 * Whether a message's reactions are the user's own to settle: they are reacting to it right now, or reacted to it after
 * the request was sent.
 */
function isReactionHeld(sentAt: number): (id: number) => boolean {
    return (id) =>
        reactionsInFlight.has(id) ||
        (reactionsSettledAt.get(id) ?? 0) >= sentAt;
}

/**
 * The thread's pinned messages once the server sent a list with the reactions version it read before it, or none.
 * A list the thread doesn't take still gets the reaction changes sent with it.
 */
function pinsFrom(
    key: string,
    thread: ThreadState,
    pinned: Message[] | null,
    version: number,
    changes: MessageReactionChange[],
    sentAt: number,
): Pins {
    const pins = acceptedPins(thread, pinned, version, pinsInFlight.has(key));

    if (pins === null) {
        return {
            pinned: withReactionChanges(thread.pinned, changes, sentAt),
            pinnedVersion: thread.pinnedVersion,
        };
    }

    return {
        pinned: withShownReactions(
            pins.pinned,
            [...thread.messages, ...thread.pinned],
            isReactionHeld(sentAt),
        ),
        pinnedVersion: pins.pinnedVersion,
    };
}

function unreadOf(conversations: ConversationSummary[]): number {
    return conversations.reduce(
        (total, conversation) => total + conversation.ahead,
        0,
    );
}

function withoutKey(
    conversations: ConversationSummary[] | null,
    key: string,
): ConversationSummary[] | null {
    return (
        conversations?.filter(
            (conversation) => summaryKey(conversation) !== key,
        ) ?? null
    );
}

export function seedInbox(inbox: InboxPage): void {
    invalidatedInboxTabs.delete('open');
    updateDuringRender({
        pinPreferences: withPinPreferences(
            inbox.conversations.map((item) => [
                summaryKey(item),
                item.pinned_at,
            ]),
        ),
    });
    updateDuringRender({
        conversations: withConversationPins(inbox.conversations),
        mutePreferences: withMutePreferences(
            inbox.conversations.map((item) => [
                summaryKey(item),
                item.muted_until_at,
            ]),
        ),
        hasMore: { ...state.hasMore, open: inbox.has_more },
        unread: state.unread ?? unreadOf(inbox.conversations),
    });
}

/**
 * The first page fresh from the server, followed by the older pages already scrolled into, without duplicates.
 */
function withFirstPage(
    current: ConversationSummary[] | null,
    firstPage: ConversationSummary[],
): ConversationSummary[] {
    const fresh = new Set(firstPage.map(summaryKey));
    const oldest = firstPage.at(-1);

    return withConversationPins([
        ...firstPage,
        ...withConversationPins(current ?? []).filter(
            (item) =>
                !fresh.has(summaryKey(item)) &&
                oldest !== undefined &&
                compareConversations(item, oldest) >= 0,
        ),
    ]);
}

async function fetchInbox(
    tab: InboxTab,
    before?: string,
    notificationBaseline = false,
    notificationConversations = false,
): Promise<
    InboxPage & {
        unread: number;
        requests: number;
        notification_baseline?: Record<string, number>;
        notification_conversations?: ConversationSummary[];
        pins?: Record<string, string | null>;
        pinsChanged: boolean;
    }
> {
    const version = ++muteVersion;
    const pinsVersion = ++pinVersion;
    const page = await request<
        InboxPage & {
            unread: number;
            requests: number;
            notification_baseline?: Record<string, number>;
            notification_conversations?: ConversationSummary[];
            pins?: Record<string, string | null>;
        }
    >(
        InboxController.index.url({
            query: {
                tab,
                ...(before === undefined ? {} : { before }),
                ...(notificationBaseline ? { notification_baseline: 1 } : {}),
                ...(notificationConversations
                    ? { notification_conversations: 1 }
                    : {}),
            },
        }),
    );
    update({
        pinPreferences: withPinPreferences(
            [
                ...(state.conversations ?? []),
                ...(state.fetched ?? []),
                ...(state.archived ?? []),
                ...page.conversations,
            ].map((item) => [
                summaryKey(item),
                page.pins === undefined
                    ? item.pinned_at
                    : (page.pins[item.id] ?? null),
            ]),
            pinsVersion,
        ),
        mutePreferences: withMutePreferences(
            [
                ...page.conversations,
                ...(page.notification_conversations ?? []),
            ].map((item) => [summaryKey(item), item.muted_until_at]),
            version,
        ),
    });
    const known = [
        ...(state.conversations ?? []),
        ...(state.fetched ?? []),
        ...(state.archived ?? []),
    ];
    const pinsChanged =
        known.some(
            (item) =>
                state.pinPreferences[summaryKey(item)]?.at !== item.pinned_at,
        ) ||
        page.conversations.some(
            (item) =>
                item.pinned_at !== null &&
                !known.some((current) => current.id === item.id),
        );
    return {
        ...page,
        pinsChanged,
        conversations: withConversationPins(page.conversations),
    };
}

/**
 * Loads a tab's first page, for the tabs that are not polled.
 */
export async function loadTab(tab: InboxTab): Promise<void> {
    const revision = inboxRevision;
    try {
        const page = await fetchInbox(tab);
        if (revision !== inboxRevision) {
            return loadTab(tab);
        }
        invalidatedInboxTabs.delete(tab);

        update({
            [listOf[tab]]: page.conversations,
            hasMore: { ...state.hasMore, [tab]: page.has_more },
            requests: page.requests,
        });
    } catch {
        update({ [listOf[tab]]: state[listOf[tab]] ?? [] });
    }
}

/**
 * The next page of a tab, once the user scrolls to the end of it.
 */
export async function loadMoreConversations(tab: InboxTab): Promise<void> {
    if (!state.hasMore[tab] || state.isLoadingMore[tab]) {
        return;
    }
    if (invalidatedInboxTabs.has(tab)) {
        await refreshConversationPages(tab, true);
        return;
    }
    const last = state[listOf[tab]]?.at(-1);
    if (last === undefined) {
        return;
    }
    const revision = inboxRevision;
    update({ isLoadingMore: { ...state.isLoadingMore, [tab]: true } });

    try {
        const page = await fetchInbox(tab, last.id);
        if (revision !== inboxRevision) {
            return;
        }
        if (page.pinsChanged) {
            invalidateConversationPages();
            await refreshConversationPages(tab, true);
            return;
        }
        const known = new Set((state[listOf[tab]] ?? []).map(summaryKey));

        update({
            [listOf[tab]]: withConversationPins([
                ...(state[listOf[tab]] ?? []),
                ...page.conversations.filter(
                    (item) => !known.has(summaryKey(item)),
                ),
            ]),
            hasMore: { ...state.hasMore, [tab]: page.has_more },
        });
    } finally {
        if (revision === inboxRevision) {
            update({ isLoadingMore: { ...state.isLoadingMore, [tab]: false } });
        }
    }
}

/**
 * A moved pin invalidates the last displayed row as a cursor. Rebuild the loaded depth as a continuous prefix.
 */
async function refreshConversationPages(
    tab: InboxTab,
    more = false,
): Promise<void> {
    const revision = inboxRevision;
    const depth = state[listOf[tab]]?.length ?? 0;
    const conversations: ConversationSummary[] = [];
    let page: Awaited<ReturnType<typeof fetchInbox>>;
    let before: string | undefined;
    let pins: string | undefined;
    update({ isLoadingMore: { ...state.isLoadingMore, [tab]: true } });
    try {
        while (true) {
            page = await fetchInbox(tab, before);
            if (revision !== inboxRevision) {
                return;
            }
            const snapshot = JSON.stringify(
                Object.entries(page.pins ?? {}).sort(([left], [right]) =>
                    left.localeCompare(right),
                ),
            );
            if (pins !== undefined && pins !== snapshot) {
                conversations.length = 0;
                before = undefined;
                pins = undefined;
                continue;
            }
            pins = snapshot;
            conversations.push(...page.conversations);
            before = page.conversations.at(-1)?.id;
            if (
                !page.has_more ||
                before === undefined ||
                conversations.length > depth ||
                (!more && conversations.length === depth)
            ) {
                break;
            }
        }
        invalidatedInboxTabs.delete(tab);
        update({
            [listOf[tab]]: withConversationPins(conversations),
            hasMore: { ...state.hasMore, [tab]: page.has_more },
        });
    } catch {
        // Keep the cursor invalid so the next scroll retries from the first page instead of skipping the gap.
    } finally {
        if (revision === inboxRevision) {
            update({ isLoadingMore: { ...state.isLoadingMore, [tab]: false } });
        }
    }
}

function emptyThread(target: ChatTarget): ThreadState {
    return {
        target,
        messages: [],
        has_older: false,
        read_through_id: 0,
        partner_read_through_id: 0,
        partner_read_at: null,
        partner_typing: false,
        members: [],
        typing: [],
        role: null,
        pinned: [],
        pinnedVersion: null,
        is_archived: false,
        pinned_at: null,
        muted_until_at: null,
        access: 'open',
        pending: [],
        reactions_version: null,
        replyingTo: null,
        head: 0,
        headUntil: 0,
        isLoaded: false,
        isLoadingOlder: false,
    };
}

function fromServer(thread: Thread | OrgThread): Partial<ThreadState> {
    return 'members' in thread
        ? {
              members: thread.members,
              typing: thread.typing,
              role: thread.role,
              is_archived: thread.is_archived,
              pinned_at: thread.pinned_at,
              muted_until_at: thread.muted_until_at,
              access: thread.access,
          }
        : {
              partner_read_through_id: thread.partner_read_through_id,
              partner_read_at: thread.partner_read_at,
              partner_typing: thread.partner_typing,
              is_archived: thread.is_archived,
              pinned_at: thread.pinned_at,
              muted_until_at: thread.muted_until_at,
              access: thread.access,
          };
}

/**
 * Pages hand over what the server already rendered, so the first paint needs no request.
 */
export function seedThread(
    target: ChatTarget,
    thread: Thread | OrgThread,
): void {
    const key = targetKey(target);
    const current = state.threads[key] ?? emptyThread(target);

    updateDuringRender({
        pinPreferences: withPinPreferences([[key, thread.pinned_at]]),
        mutePreferences: withMutePreferences([[key, thread.muted_until_at]]),
        threads: {
            ...state.threads,
            [key]: {
                ...current,
                ...fromServer(thread),
                target,
                messages: mergeMessages(current.messages, thread.messages),
                ...pinsFrom(
                    key,
                    current,
                    'pinned' in thread ? thread.pinned : null,
                    thread.reactions_version,
                    [],
                    performance.now(),
                ),
                has_older: current.isLoaded
                    ? current.has_older
                    : thread.has_older,
                read_through_id: thread.read_through_id,
                reactions_version: thread.reactions_version,
                head: thread.read_through_id,
                headUntil: thread.messages.at(-1)?.id ?? 0,
                isLoaded: true,
                isLoadingOlder: false,
            },
        },
    });
}

/**
 * Opens a conversation before anything was written in it, or before its first poll.
 */
export function rememberTarget(target: ChatTarget): void {
    const key = targetKey(target);

    if (state.threads[key] === undefined) {
        update({
            threads: { ...state.threads, [key]: emptyThread(target) },
        });
    }
}

async function pollInbox(): Promise<void> {
    const isPaused = document.hidden && remoteAlerts === null;

    if (isPaused && state.unread !== null) {
        update({ unread: null });
    }

    if (isPaused || isFetchingInbox) {
        return;
    }

    isFetchingInbox = true;
    const revision = inboxRevision;

    try {
        const alerts = remoteAlerts;
        const {
            conversations,
            unread,
            requests,
            notification_baseline,
            notification_conversations,
            pinsChanged,
        } = await fetchInbox(
            'open',
            undefined,
            alerts?.needsBaseline(),
            alerts !== null,
        );
        if (revision !== inboxRevision) {
            return;
        }
        const notifications = notification_conversations ?? conversations;
        const isReading = (conversation: ConversationSummary) =>
            !document.hidden &&
            document.hasFocus() &&
            isWatching(summaryKey(conversation));
        if (
            alerts === remoteAlerts &&
            (!alerts?.needsBaseline() || notification_baseline !== undefined)
        ) {
            void alerts?.receive(
                notifications.map((conversation) => ({
                    ...conversation,
                    muted_until_at:
                        state.mutePreferences[summaryKey(conversation)].until,
                })),
                (conversation) =>
                    isReading(conversation) ||
                    isRemoteMuted(
                        state.mutePreferences[summaryKey(conversation)].until,
                    ),
                notification_baseline,
            );
        }
        if (pinsChanged) {
            invalidateConversationPages();
        }
        const reconcileOpen = invalidatedInboxTabs.has('open');
        await Promise.all(
            [...invalidatedInboxTabs].map((tab) =>
                refreshConversationPages(tab),
            ),
        );
        const requestsChanged =
            state.requests !== null && requests !== state.requests;
        const watched = conversations.filter(isReading);

        update({
            conversations: reconcileOpen
                ? state.conversations
                : withFirstPage(
                      state.conversations,
                      conversations.map((conversation) =>
                          isReading(conversation)
                              ? { ...conversation, ahead: 0 }
                              : conversation,
                      ),
                  ),
            fetched: state.fetched && withConversationPins(state.fetched),
            archived: state.archived && withConversationPins(state.archived),
            requests,
            unread:
                unread -
                watched.reduce(
                    (total, conversation) => total + conversation.ahead,
                    0,
                ),
        });

        if (requestsChanged && state.fetched !== null) {
            void loadTab('fetched');
        }
    } catch {
        // Keep the last known inbox until the next poll.
    } finally {
        isFetchingInbox = false;
    }
}

export function watchRemoteAlerts(userId: string): () => void {
    let titleTimer: number | undefined;
    let pendingAlert: { key: string; title: string } | null = null;
    const channel =
        'BroadcastChannel' in window
            ? new BroadcastChannel(`remote-titles:${userId}`)
            : null;
    const clearTitle = () => {
        window.clearInterval(titleTimer);
        pendingAlert = null;
        update({ notificationTitle: null });
    };
    const acknowledgeTitle = () => {
        if (
            pendingAlert === null ||
            document.hidden ||
            !document.hasFocus() ||
            !isWatching(pendingAlert.key)
        )
            return;

        const key = pendingAlert.key;
        clearTitle();
        channel?.postMessage({ key, title: null });
    };
    const showTitle = (alert: { key: string; title: string }) => {
        if (!remoteAlertsEnabled(userId)) return;

        const alreadyPending = pendingAlert !== null;
        pendingAlert = alert;
        acknowledgeTitle();
        if (pendingAlert === null) return;

        if (alreadyPending) {
            if (state.notificationTitle !== null) {
                update({ notificationTitle: pendingAlert.title });
            }
            return;
        }

        update({ notificationTitle: pendingAlert.title });
        let switches = 0;
        titleTimer = window.setInterval(() => {
            switches++;
            update({
                notificationTitle:
                    switches % 2 === 0 ? (pendingAlert?.title ?? null) : null,
            });
            if (switches === 6) window.clearInterval(titleTimer);
        }, 1_000);
    };
    channel?.addEventListener('message', (event: MessageEvent<unknown>) => {
        const alert = event.data;
        if (
            typeof alert !== 'object' ||
            alert === null ||
            !('key' in alert) ||
            typeof alert.key !== 'string' ||
            !('title' in alert)
        )
            return;
        if (alert.title === null && pendingAlert?.key === alert.key)
            clearTitle();
        else if (typeof alert.title === 'string')
            showTitle({ key: alert.key, title: alert.title });
    });
    window.addEventListener('focus', acknowledgeTitle);
    document.addEventListener('visibilitychange', acknowledgeTitle);
    const unsubscribe = subscribeToChat(acknowledgeTitle);

    const alerts = createRemoteAlerts(
        userId,
        (conversation) => urls(targetOf(conversation)).show,
        () =>
            !document.hidden && document.hasFocus()
                ? [...threadWatchers.keys()].map(
                      (key) => urls(state.threads[key].target).show,
                  )
                : [],
        (conversation) => {
            const sender = conversation.last_incoming_sender ?? 'Someone';
            const remote =
                conversation.kind === 'org'
                    ? ` in ${conversation.name || 'an unnamed org'}`
                    : '';
            const title = `${sender}${remote} sent you a message`;
            const alert = { key: summaryKey(conversation), title };
            channel?.postMessage(alert);
            showTitle(alert);
        },
    );
    remoteAlerts = alerts;
    const unwatch = watchInbox();

    return () => {
        alerts.stop();
        window.clearInterval(titleTimer);
        window.removeEventListener('focus', acknowledgeTitle);
        document.removeEventListener('visibilitychange', acknowledgeTitle);
        channel?.close();
        unsubscribe();
        if (remoteAlerts === alerts) {
            remoteAlerts = null;
            update({ notificationTitle: null });
        }
        unwatch();
    };
}

function scheduleInbox(): void {
    window.clearTimeout(inboxTimer);

    if (inboxWatchers === 0) {
        return;
    }

    inboxTimer = window.setTimeout(
        () => void pollInbox().finally(scheduleInbox),
        threadWatchers.size > 0 ? busyInboxPoll : inboxPoll,
    );
}

function isWatching(key: string): boolean {
    return (threadWatchers.get(key) ?? 0) > 0;
}

/**
 * Keeps the inbox fresh while something shows it. Returns the function that stops watching.
 */
export function watchInbox(): () => void {
    inboxWatchers++;

    if (inboxWatchers === 1) {
        update({ isPollingInbox: true });
        void pollInbox();
        scheduleInbox();
    }

    const onVisible = () => {
        if (!document.hidden) {
            void pollInbox();
        }
    };

    document.addEventListener('visibilitychange', onVisible);

    return () => {
        inboxWatchers--;
        document.removeEventListener('visibilitychange', onVisible);
        scheduleInbox();

        if (inboxWatchers === 0) {
            update({ isPollingInbox: false });
        }
    };
}

async function pollThread(target: ChatTarget): Promise<void> {
    const key = targetKey(target);
    const thread = state.threads[key];
    const after = thread?.isLoaded ? (thread.messages.at(-1)?.id ?? 0) : null;
    const reactionsAfter =
        after === null
            ? null
            : reactionsAfterOf(
                  thread?.reactions_version ?? null,
                  target.kind === 'org' ? (thread?.pinnedVersion ?? 0) : null,
              );
    const polledAt = performance.now();
    const version = ++muteVersion;
    const pinsVersion = ++pinVersion;

    const fresh = await request<
        (Thread & { partner: User }) | (OrgThread & { org: OrgRef })
    >(
        urls(target).index(
            after === null
                ? undefined
                : {
                      after,
                      ...(reactionsAfter === null
                          ? {}
                          : { reactions_after: reactionsAfter }),
                  },
        ),
    );

    const current = state.threads[key] ?? emptyThread(target);
    const isTyping =
        'members' in fresh ? fresh.typing.length > 0 : fresh.partner_typing;

    if (fresh.messages.length > 0 || isTyping) {
        lastActivity.set(key, Date.now());
    }

    update({
        pinPreferences: withPinPreferences(
            [[key, fresh.pinned_at]],
            pinsVersion,
        ),
        mutePreferences: withMutePreferences(
            [[key, fresh.muted_until_at]],
            version,
        ),
        threads: {
            ...state.threads,
            [key]: {
                ...current,
                ...fromServer(fresh),
                target:
                    'org' in fresh
                        ? { kind: 'org', org: fresh.org }
                        : current.isLoaded
                          ? current.target
                          : { kind: 'direct', partner: fresh.partner },
                messages: mergeMessages(
                    withReactionChanges(
                        current.messages,
                        fresh.reaction_changes,
                        polledAt,
                    ),
                    fresh.messages,
                ),
                ...pinsFrom(
                    key,
                    current,
                    'pinned' in fresh ? fresh.pinned : null,
                    fresh.reactions_version,
                    fresh.reaction_changes,
                    polledAt,
                ),
                reactions_version: Math.max(
                    current.reactions_version ?? 0,
                    fresh.reactions_version,
                ),
                pending:
                    fresh.messages.length === 0
                        ? current.pending
                        : current.pending.filter(
                              (pending) =>
                                  !fresh.messages.some(
                                      (message) =>
                                          message.client_id ===
                                          pending.client_id,
                                  ),
                          ),
                has_older: current.isLoaded
                    ? current.has_older
                    : fresh.has_older,
                read_through_id: Math.max(
                    current.read_through_id,
                    fresh.read_through_id,
                ),
                head: current.isLoaded ? current.head : fresh.read_through_id,
                headUntil: current.isLoaded
                    ? current.headUntil
                    : (fresh.messages.at(-1)?.id ?? 0),
                isLoaded: true,
            },
        },
    });
}

/**
 * Polls an open conversation quickly while it is lively and slower once it goes quiet, and not at all in a
 * hidden tab. Returns the function that stops watching.
 */
export function watchThread(target: ChatTarget): () => void {
    const key = targetKey(target);
    let timer: number | undefined;
    let isStopped = false;

    threadWatchers.set(key, (threadWatchers.get(key) ?? 0) + 1);

    if (state.threads[key] === undefined) {
        update({
            threads: { ...state.threads, [key]: emptyThread(target) },
        });
    } else {
        updateThread(key, (thread) => ({
            head: thread.isLoaded ? thread.read_through_id : thread.head,
            headUntil: thread.isLoaded
                ? (thread.messages.at(-1)?.id ?? 0)
                : thread.headUntil,
        }));
    }

    remoteAlerts?.updateReading();

    const tick = async () => {
        window.clearTimeout(timer);

        if (!document.hidden) {
            try {
                await pollThread(target);
            } catch {
                // Keep the conversation as it is until the next poll.
            }
        }

        if (isStopped) {
            return;
        }

        const isActive =
            Date.now() - (lastActivity.get(key) ?? 0) < activeWindow;

        timer = window.setTimeout(
            () => void tick(),
            isActive ? activePoll : idlePoll,
        );
    };

    const onVisible = () => {
        if (!document.hidden) {
            void tick();
        }
    };

    document.addEventListener('visibilitychange', onVisible);
    void tick();
    scheduleInbox();
    void loadReactionUsage();

    return () => {
        isStopped = true;
        window.clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);

        const watchers = (threadWatchers.get(key) ?? 1) - 1;

        if (watchers === 0) {
            threadWatchers.delete(key);
        } else {
            threadWatchers.set(key, watchers);
        }

        remoteAlerts?.updateReading();
        scheduleInbox();
    };
}

function touchInbox(target: ChatTarget, message: Message): void {
    const key = targetKey(target);
    const conversations = state.conversations ?? [];
    const existing = [...conversations, ...(state.archived ?? [])].find(
        (conversation) => summaryKey(conversation) === key,
    );

    if (existing === undefined && target.kind === 'org') {
        void pollInbox();

        return;
    }

    const summary: ConversationSummary = {
        id: existing?.id ?? '',
        kind: target.kind,
        name: existing?.name ?? null,
        partner: target.kind === 'direct' ? target.partner : null,
        members: existing?.members ?? null,
        last_message: message,
        last_incoming_message_id: existing?.last_incoming_message_id ?? null,
        last_incoming_sender: existing?.last_incoming_sender ?? null,
        last_message_at: message.created_at,
        ahead: existing?.ahead ?? 0,
        partner_read_through_id: existing?.partner_read_through_id ?? 0,
        partner_read_at: existing?.partner_read_at ?? null,
        is_archived: false,
        pinned_at: state.pinPreferences[key]?.at ?? existing?.pinned_at ?? null,
        muted_until_at: existing?.muted_until_at ?? null,
    };

    update({
        conversations: withConversationPins([
            summary,
            ...(withoutKey(conversations, key) ?? []),
        ]),
        archived: withoutKey(state.archived, key),
    });
    updateThread(key, () => ({ is_archived: false }));
}

async function deliver(
    target: ChatTarget,
    pending: PendingMessage,
): Promise<void> {
    const key = targetKey(target);

    try {
        const { message } = await request<{ message: Message }>(
            urls(target).store,
            'POST',
            payloadOf(pending),
        );

        lastActivity.set(key, Date.now());

        if (state.threads[key]?.messages.length === 0) {
            void pollThread(target).catch(() => undefined);
        }

        updateThread(key, (thread) => ({
            messages: mergeMessages(thread.messages, [message]),
            pending: thread.pending.filter(
                (item) => item.client_id !== pending.client_id,
            ),
            read_through_id: Math.max(thread.read_through_id, message.id),
            head: Math.max(thread.head, message.id),
        }));
        touchInbox(target, message);
    } catch {
        updateThread(key, (thread) => ({
            pending: thread.pending.map((item) =>
                item.client_id === pending.client_id
                    ? { ...item, status: 'rejected' }
                    : item,
            ),
        }));
    }
}

function payloadOf(pending: PendingMessage): object | FormData {
    const fields = {
        body: pending.body,
        client_id: pending.client_id,
        ...(pending.reply_to === null
            ? {}
            : { reply_to_id: pending.reply_to.id }),
        ...(pending.link_preview_url === undefined
            ? {}
            : { link_preview_url: pending.link_preview_url ?? '' }),
    };

    if (pending.images.length === 0 && pending.voice === null) {
        return fields;
    }

    const form = new FormData();

    Object.entries(fields).forEach(([name, value]) =>
        form.append(name, String(value)),
    );
    pending.images.forEach((image, index) => {
        form.append('images[]', image);
        form.append(
            `image_descriptions[${index}]`,
            pending.image_descriptions[index]?.trim() ?? '',
        );
    });

    if (pending.voice !== null) {
        form.append('voice', pending.voice.file);
        pending.voice.waveform.forEach((level) =>
            form.append('voice_waveform[]', String(level)),
        );
    }

    return form;
}

/**
 * Shows the message straight away as staged, then swaps in the stored message once the server has it.
 */
export function sendMessage(target: ChatTarget, draft: MessageDraft): void {
    const key = targetKey(target);

    rememberTarget(target);

    const pending: PendingMessage = {
        client_id: crypto.randomUUID(),
        body: draft.body,
        reply_to: draft.replyTo,
        images: draft.images,
        image_descriptions: draft.descriptions,
        link_preview_url: draft.linkPreviewUrl,
        voice: draft.voice ?? null,
        created_at: new Date().toISOString(),
        status: 'staged',
    };

    lastTypingPing.delete(key);
    updateThread(key, (thread) => ({
        pending: [...thread.pending, pending],
    }));
    void deliver(target, pending);
}

async function loadReactionUsage(): Promise<void> {
    if (state.reactionUsage !== null || isLoadingReactionUsage) {
        return;
    }

    isLoadingReactionUsage = true;

    try {
        const { usage } = await request<{ usage: ReactionUsage[] }>(
            MessageReactionController.index.url(),
        );

        update({ reactionUsage: usage });
    } catch {
        // The picker falls back to the default quick reactions.
    } finally {
        isLoadingReactionUsage = false;
    }
}

function countReactionUse(emoji: string, change: 1 | -1): void {
    if (state.reactionUsage !== null) {
        update({
            reactionUsage: withReactionUse(state.reactionUsage, emoji, change),
        });
    }
}

/**
 * A message can show twice, in the thread and among the pinned ones, or only among the pinned ones for a member added
 * after it without its history.
 */
function setReactions(
    key: string,
    messageId: number,
    change: (reactions: MessageReaction[]) => MessageReaction[],
): void {
    const changed = (messages: Message[]) =>
        messages.some((message) => message.id === messageId)
            ? messages.map((message) =>
                  message.id === messageId
                      ? {
                            ...message,
                            reactions: change(message.reactions ?? []),
                        }
                      : message,
              )
            : messages;

    updateThread(key, (thread) => ({
        messages: changed(thread.messages),
        pinned: changed(thread.pinned),
    }));
}

/**
 * Adds the user's reaction, or takes it back when they already left it, straight away, then settles on what the
 * server holds. Returns whether the server took it.
 */
export async function toggleReaction(
    target: ChatTarget,
    messageId: number,
    emoji: string,
    userId: string,
): Promise<boolean> {
    const key = targetKey(target);
    const thread = state.threads[key];
    const message = [
        ...(thread?.messages ?? []),
        ...(thread?.pinned ?? []),
    ].find((item) => item.id === messageId);

    if (message === undefined) {
        return false;
    }

    const isMine = (reaction: MessageReaction) =>
        reaction.user_id === userId && reaction.emoji === emoji;
    const isRemoving = (message.reactions ?? []).some(isMine);
    const add = (reactions: MessageReaction[]) =>
        reactions.some(isMine)
            ? reactions
            : [...reactions, { user_id: userId, emoji }];
    const remove = (reactions: MessageReaction[]) =>
        reactions.filter((reaction) => !isMine(reaction));
    const settle = () => {
        const left = (reactionsInFlight.get(messageId) ?? 1) - 1;

        reactionsSettledAt.set(messageId, performance.now());

        if (left === 0) {
            reactionsInFlight.delete(messageId);
        } else {
            reactionsInFlight.set(messageId, left);
        }

        return left === 0;
    };

    reactionsInFlight.set(
        messageId,
        (reactionsInFlight.get(messageId) ?? 0) + 1,
    );
    setReactions(key, messageId, isRemoving ? remove : add);
    countReactionUse(emoji, isRemoving ? -1 : 1);

    try {
        const { reactions } = await request<{ reactions: MessageReaction[] }>(
            isRemoving
                ? MessageReactionController.destroy.url({ message: messageId })
                : MessageReactionController.store.url({ message: messageId }),
            isRemoving ? 'DELETE' : 'POST',
            { emoji },
        );

        if (settle()) {
            setReactions(key, messageId, () => reactions);
        }

        return true;
    } catch {
        settle();
        setReactions(key, messageId, isRemoving ? add : remove);
        countReactionUse(emoji, isRemoving ? 1 : -1);

        return false;
    }
}

/**
 * Pins a message for the whole org, or unpins it, straight away, then settles on the org's pinned messages as the
 * server holds them, once no other pin request in the org is in flight. Puts the message back where it was and throws
 * when the server refuses.
 */
export async function setMessagePinned(
    target: ChatTarget,
    message: Message,
    isPinned: boolean,
): Promise<void> {
    const key = targetKey(target);
    const shown = state.threads[key]?.pinned ?? [];
    const index = shown.findIndex((item) => item.id === message.id);
    const sentAt = performance.now();

    if (isPinned === (index !== -1)) {
        return;
    }

    pinsInFlight.set(key, (pinsInFlight.get(key) ?? 0) + 1);
    updateThread(key, (thread) => ({
        pinned: isPinned
            ? withPin(thread.pinned, message)
            : withoutPin(thread.pinned, message),
    }));

    try {
        const { pinned, reactions_version: version } = await request<{
            pinned: Message[];
            reactions_version: number;
        }>(
            isPinned
                ? MessagePinController.update.url({ message: message.id })
                : MessagePinController.destroy.url({ message: message.id }),
            isPinned ? 'PUT' : 'DELETE',
        );

        // Responses can arrive out of order, so the newest one wins.
        if ((newestPins.get(key)?.version ?? -1) <= version) {
            newestPins.set(key, { pinned, version, sentAt });
        }
    } catch (error) {
        updateThread(key, (thread) => ({
            pinned: isPinned
                ? withoutPin(thread.pinned, message)
                : withPinRestored(thread.pinned, shown[index], index),
        }));

        throw error;
    } finally {
        const left = (pinsInFlight.get(key) ?? 1) - 1;
        const newest = newestPins.get(key);

        if (left > 0) {
            pinsInFlight.set(key, left);
        } else {
            pinsInFlight.delete(key);
            newestPins.delete(key);

            if (newest !== undefined) {
                updateThread(key, (thread) =>
                    pinsFrom(
                        key,
                        thread,
                        newest.pinned,
                        newest.version,
                        [],
                        newest.sentAt,
                    ),
                );
            }
        }
    }
}

export function setReplyingTo(
    target: ChatTarget,
    reply: MessageReply | null,
): void {
    updateThread(targetKey(target), () => ({ replyingTo: reply }));
}

/**
 * Sends a rejected message again with its original client id, so the server stores it once at most.
 */
export function retryMessage(target: ChatTarget, clientId: string): void {
    const key = targetKey(target);
    const pending = state.threads[key]?.pending.find(
        (item) => item.client_id === clientId,
    );

    if (pending === undefined) {
        return;
    }

    updateThread(key, (thread) => ({
        pending: thread.pending.map((item) =>
            item.client_id === clientId ? { ...item, status: 'staged' } : item,
        ),
    }));
    void deliver(target, { ...pending, status: 'staged' });
}

export function markThreadRead(target: ChatTarget): void {
    if (document.hidden || !document.hasFocus()) return;

    const key = targetKey(target);
    const thread = state.threads[key];
    const latest = thread?.messages.at(-1)?.id ?? 0;

    if (thread === undefined || latest <= thread.read_through_id) {
        return;
    }

    updateThread(key, () => ({ read_through_id: latest }));

    const conversations = (state.conversations ?? []).map((conversation) =>
        summaryKey(conversation) === key
            ? { ...conversation, ahead: 0 }
            : conversation,
    );

    update({ conversations, unread: unreadOf(conversations) });

    void request(urls(target).read, 'PUT', { through: latest }).catch(
        () => undefined,
    );
}

export function pingTyping(target: ChatTarget): void {
    const key = targetKey(target);
    const now = Date.now();

    if (now - (lastTypingPing.get(key) ?? 0) < typingPing) {
        return;
    }

    lastTypingPing.set(key, now);
    void request(urls(target).typing, 'PUT').catch(() => undefined);
}

const olderLoads = new Map<string, Promise<void>>();

export async function loadOlderMessages(target: ChatTarget): Promise<void> {
    const key = targetKey(target);
    const loading = olderLoads.get(key);

    if (loading !== undefined) {
        return loading;
    }

    const thread = state.threads[key];
    const oldest = thread?.messages[0]?.id;

    if (
        thread === undefined ||
        oldest === undefined ||
        !thread.has_older ||
        thread.isLoadingOlder
    ) {
        return;
    }

    updateThread(key, () => ({ isLoadingOlder: true }));

    const load = (async () => {
        try {
            const older = await request<Thread | OrgThread>(
                urls(target).index({ before: oldest }),
            );

            updateThread(key, (current) => ({
                messages: mergeMessages(current.messages, older.messages),
                has_older: older.has_older,
                isLoadingOlder: false,
            }));
        } catch {
            updateThread(key, () => ({ isLoadingOlder: false }));
        }
    })();
    olderLoads.set(key, load);

    try {
        await load;
    } finally {
        olderLoads.delete(key);
    }
}

/**
 * Keep the history continuous when following a reply, sharing pages with ordinary scrolling.
 */
export async function loadMessage(
    target: ChatTarget,
    id: number,
    signal: AbortSignal,
): Promise<boolean> {
    const key = targetKey(target);

    while (!signal.aborted) {
        const thread = state.threads[key];
        const oldest = thread?.messages[0]?.id;

        if (thread?.messages.some((message) => message.id === id)) {
            return true;
        }

        if (oldest === undefined || oldest <= id || !thread.has_older) {
            return false;
        }

        await loadOlderMessages(target);

        if (state.threads[key]?.messages[0]?.id === oldest) {
            return false;
        }
    }

    return false;
}

/**
 * Searches the user's conversations by a partner's or member's name or handle, or an org's name, on whichever tab
 * is open.
 */
export async function searchConversations(
    term: string,
    tab: InboxTab,
    signal: AbortSignal,
): Promise<ConversationSummary[]> {
    const version = ++muteVersion;
    const pinsVersion = ++pinVersion;
    const response = await fetch(
        InboxController.index.url({ query: { q: term, tab } }),
        { headers: { Accept: 'application/json' }, cache: 'no-store', signal },
    );

    if (!response.ok) {
        throw new Error(`Conversation search failed: ${response.status}`);
    }

    const { conversations } = (await response.json()) as InboxPage;
    update({
        pinPreferences: withPinPreferences(
            conversations.map((item) => [summaryKey(item), item.pinned_at]),
            pinsVersion,
        ),
        mutePreferences: withMutePreferences(
            conversations.map((item) => [
                summaryKey(item),
                item.muted_until_at,
            ]),
            version,
        ),
    });
    return withConversationPins(conversations);
}

/**
 * Developers to message or add to an org, each with the reason they can't be, if there is one. Those who can be
 * picked come first.
 */
export async function searchCandidates(
    term: string,
    scope: { for: 'remote' | 'org' } | { org: string },
    signal: AbortSignal,
): Promise<Candidate[]> {
    const response = await fetch(
        RemoteCandidateController.index.url({ query: { q: term, ...scope } }),
        { headers: { Accept: 'application/json' }, cache: 'no-store', signal },
    );

    if (!response.ok) {
        return [];
    }

    const { candidates } = (await response.json()) as {
        candidates: Candidate[];
    };

    return candidates.toSorted(
        (a, b) => Number(a.reason !== null) - Number(b.reason !== null),
    );
}

/**
 * Merges a fetched request into the open conversations, or drops it. The sender is never told about a drop.
 */
export async function settleRequest(
    partner: User,
    isMerging: boolean,
): Promise<boolean> {
    const key = partner.username;

    try {
        await request(
            isMerging
                ? ConversationMergeController.store.url({ partner: key })
                : ConversationDropController.store.url({ partner: key }),
            'POST',
        );
    } catch {
        return false;
    }

    const fetchedRequest = state.fetched?.find(
        (item) => summaryKey(item) === key,
    );

    update({
        fetched: withoutKey(state.fetched, key),
        requests: Math.max(0, (state.requests ?? 1) - 1),
        unread: Math.max(0, (state.unread ?? 1) - 1),
        ...(isMerging && fetchedRequest
            ? {
                  conversations: withConversationPins([
                      ...(withoutKey(state.conversations, key) ?? []),
                      { ...fetchedRequest, ahead: 0 },
                  ]),
              }
            : {}),
    });
    updateThread(key, () => ({ access: isMerging ? 'open' : 'waiting' }));

    return true;
}

/**
 * Moves the conversation between the inbox and the stash straight away, and back if the server refuses.
 */
export async function setConversationArchived(
    target: ChatTarget,
    isArchived: boolean,
): Promise<boolean> {
    const key = targetKey(target);
    const previous = {
        conversations: state.conversations,
        archived: state.archived,
        unread: state.unread,
    };
    const conversation = [
        ...(state.conversations ?? []),
        ...(state.archived ?? []),
    ].find((item) => summaryKey(item) === key);

    if (conversation !== undefined) {
        const moved = { ...conversation, is_archived: isArchived };
        const conversations = isArchived
            ? withoutKey(state.conversations, key)
            : withConversationPins([
                  ...(withoutKey(state.conversations, key) ?? []),
                  moved,
              ]);
        const archived = isArchived
            ? state.archived &&
              withConversationPins([
                  ...(withoutKey(state.archived, key) ?? []),
                  moved,
              ])
            : withoutKey(state.archived, key);

        update({
            conversations,
            archived,
            unread:
                state.unread === null
                    ? null
                    : state.unread +
                      (isArchived ? -conversation.ahead : conversation.ahead),
        });
    }

    updateThread(key, () => ({ is_archived: isArchived }));

    try {
        await request(
            isArchived ? urls(target).stash : urls(target).pop,
            isArchived ? 'POST' : 'DELETE',
        );

        return true;
    } catch {
        update(previous);
        updateThread(key, () => ({ is_archived: !isArchived }));

        return false;
    }
}

function invalidateConversationPages(): void {
    inboxRevision++;
    for (const tab of ['open', 'fetched', 'stashed'] as const) {
        if (state[listOf[tab]] !== null && state.hasMore[tab]) {
            invalidatedInboxTabs.add(tab);
        }
    }
}

export async function setConversationPinned(
    target: ChatTarget,
    pinned: boolean,
): Promise<void> {
    const preference = await request<{ pinned_at: string | null }>(
        urls(target).pin,
        'PUT',
        { pinned },
    );
    const key = targetKey(target);
    invalidateConversationPages();
    update({
        pinPreferences: withPinPreferences([[key, preference.pinned_at]]),
    });
    update({
        conversations:
            state.conversations && withConversationPins(state.conversations),
        fetched: state.fetched && withConversationPins(state.fetched),
        archived: state.archived && withConversationPins(state.archived),
    });
    updateThread(key, () => preference);
    await Promise.all(
        [...invalidatedInboxTabs].map((tab) => refreshConversationPages(tab)),
    );
}

export type MuteDuration = 'hour' | 'tomorrow' | 'week' | 'forever' | 'off';

export async function setConversationMute(
    target: ChatTarget,
    duration: MuteDuration,
): Promise<void> {
    const preference = await request<{ muted_until_at: string | null }>(
        urls(target).mute,
        'PUT',
        { duration },
    );
    const key = targetKey(target);
    const apply = (items: ConversationSummary[] | null) =>
        items?.map((item) =>
            summaryKey(item) === key ? { ...item, ...preference } : item,
        ) ?? null;

    update({
        mutePreferences: withMutePreferences([
            [key, preference.muted_until_at],
        ]),
        conversations: apply(state.conversations),
        fetched: apply(state.fetched),
        archived: apply(state.archived),
    });
    updateThread(key, () => preference);
}

/**
 * Blocks through the same endpoint as profiles. Its redirect back to the conversation is not followed, since
 * that conversation no longer exists for either of them.
 */
export async function blockPartner(partner: User): Promise<boolean> {
    const response = await fetch(UserBlockController.store.url(partner), {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        redirect: 'manual',
    }).catch(() => null);

    if (
        response === null ||
        (response.type !== 'opaqueredirect' && !response.ok)
    ) {
        return false;
    }

    const threads = { ...state.threads };
    delete threads[partner.username];

    update({
        conversations: withoutKey(state.conversations, partner.username),
        fetched: withoutKey(state.fetched, partner.username),
        archived: withoutKey(state.archived, partner.username),
        threads,
    });

    return true;
}

/**
 * Starts an org with the people picked, and returns it.
 */
export async function createOrg(
    name: string,
    members: string[],
): Promise<OrgRef> {
    const { org } = await request<{ org: { id: string } }>(
        OrgController.store.url(),
        'POST',
        { name, members },
    );

    void pollInbox();

    return { id: org.id, name: name.trim() || null };
}

async function refreshOrg(org: OrgRef): Promise<void> {
    await pollThread({ kind: 'org', org }).catch(() => undefined);
    void pollInbox();
}

export async function renameOrg(org: OrgRef, name: string): Promise<void> {
    await request(OrgController.update.url({ org: org.id }), 'PUT', { name });

    const key = targetKey({ kind: 'org', org });
    const renamed = { ...org, name: name.trim() || null };

    updateThread(key, () => ({ target: { kind: 'org', org: renamed } }));
    await refreshOrg(renamed);
}

export async function addOrgMembers(
    org: OrgRef,
    members: string[],
    sharesHistory: boolean,
): Promise<void> {
    await request(OrgMemberController.store.url({ org: org.id }), 'POST', {
        members,
        share_history: sharesHistory,
    });
    await refreshOrg(org);
}

export async function setOrgMemberRole(
    org: OrgRef,
    member: User,
    role: OrgRole,
): Promise<void> {
    await request(
        OrgMemberController.update.url({
            org: org.id,
            member: member.username,
        }),
        'PUT',
        { role },
    );
    await refreshOrg(org);
}

/**
 * Removes a member, or leaves when the member is the user. Leaving takes the org out of the inbox.
 */
export async function removeOrgMember(
    org: OrgRef,
    member: User,
    isLeaving: boolean,
): Promise<void> {
    await request(
        OrgMemberController.destroy.url({
            org: org.id,
            member: member.username,
        }),
        'DELETE',
    );

    if (!isLeaving) {
        await refreshOrg(org);

        return;
    }

    const key = targetKey({ kind: 'org', org });
    const threads = { ...state.threads };
    delete threads[key];

    update({
        conversations: withoutKey(state.conversations, key),
        archived: withoutKey(state.archived, key),
        threads,
    });
}
