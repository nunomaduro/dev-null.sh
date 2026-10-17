import ConversationArchiveController from '@/actions/App/Http/Controllers/ConversationArchiveController';
import ConversationDropController from '@/actions/App/Http/Controllers/ConversationDropController';
import ConversationMergeController from '@/actions/App/Http/Controllers/ConversationMergeController';
import ConversationMessageController from '@/actions/App/Http/Controllers/ConversationMessageController';
import ConversationReadController from '@/actions/App/Http/Controllers/ConversationReadController';
import ConversationTypingController from '@/actions/App/Http/Controllers/ConversationTypingController';
import InboxController from '@/actions/App/Http/Controllers/InboxController';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import type {
    ConversationSummary,
    InboxPage,
    InboxTab,
    Message,
    Thread,
    User,
} from '@/types';

export type PendingMessage = {
    client_id: string;
    body: string;
    created_at: string;
    status: 'staged' | 'rejected';
};

export type ThreadState = Thread & {
    partner: User;
    pending: PendingMessage[];
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
    threads: Record<string, ThreadState>;
};

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
    threads: {},
};
const listeners = new Set<() => void>();
const threadWatchers = new Map<string, number>();
const lastActivity = new Map<string, number>();
const lastTypingPing = new Map<string, number>();
let inboxWatchers = 0;
let inboxTimer: number | undefined;

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

function updateThread(
    username: string,
    change: (thread: ThreadState) => Partial<ThreadState>,
): void {
    const thread = state.threads[username];

    if (thread === undefined) {
        return;
    }

    update({
        threads: {
            ...state.threads,
            [username]: { ...thread, ...change(thread) },
        },
    });
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' = 'GET',
    body?: object,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        cache: 'no-store',
        credentials: 'same-origin',
        signal: AbortSignal.timeout(10_000),
    });

    if (!response.ok) {
        throw new Error(`Chat request failed: ${response.status}`);
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

function unreadOf(conversations: ConversationSummary[]): number {
    return conversations.reduce(
        (total, conversation) => total + conversation.ahead,
        0,
    );
}

export function seedInbox(inbox: InboxPage): void {
    updateDuringRender({
        conversations: inbox.conversations,
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
    const fresh = new Set(firstPage.map((item) => item.partner.id));
    const oldest = firstPage.at(-1)?.last_message_at ?? '';

    return [
        ...firstPage,
        ...(current ?? []).filter(
            (item) =>
                !fresh.has(item.partner.id) &&
                (item.last_message_at ?? '') <= oldest,
        ),
    ];
}

async function fetchInbox(
    tab: InboxTab,
    before?: number,
): Promise<InboxPage & { unread: number; requests: number }> {
    return request(
        InboxController.index.url({
            query: { tab, ...(before === undefined ? {} : { before }) },
        }),
    );
}

/**
 * Loads a tab's first page, for the tabs that are not polled.
 */
export async function loadTab(tab: InboxTab): Promise<void> {
    try {
        const page = await fetchInbox(tab);

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
    const list = state[listOf[tab]];
    const last = list?.at(-1);

    if (!state.hasMore[tab] || state.isLoadingMore[tab] || last === undefined) {
        return;
    }

    update({ isLoadingMore: { ...state.isLoadingMore, [tab]: true } });

    try {
        const page = await fetchInbox(tab, last.id);
        const known = new Set(
            (state[listOf[tab]] ?? []).map((item) => item.partner.id),
        );

        update({
            [listOf[tab]]: [
                ...(state[listOf[tab]] ?? []),
                ...page.conversations.filter(
                    (item) => !known.has(item.partner.id),
                ),
            ],
            hasMore: { ...state.hasMore, [tab]: page.has_more },
        });
    } finally {
        update({ isLoadingMore: { ...state.isLoadingMore, [tab]: false } });
    }
}

/**
 * Pages hand over what the server already rendered, so the first paint needs no request.
 */
export function seedThread(partner: User, thread: Thread): void {
    const current = state.threads[partner.username];

    updateDuringRender({
        threads: {
            ...state.threads,
            [partner.username]: {
                ...thread,
                messages: mergeMessages(
                    current?.messages ?? [],
                    thread.messages,
                ),
                has_older: current?.has_older ?? thread.has_older,
                partner,
                pending: current?.pending ?? [],
                head: thread.read_through_id,
                headUntil: thread.messages.at(-1)?.id ?? 0,
                isLoaded: true,
                isLoadingOlder: false,
            },
        },
    });
}

function emptyThread(partner: User): ThreadState {
    return {
        partner,
        messages: [],
        has_older: false,
        read_through_id: 0,
        partner_read_through_id: 0,
        partner_read_at: null,
        partner_typing: false,
        is_archived: false,
        access: 'open',
        pending: [],
        head: 0,
        headUntil: 0,
        isLoaded: false,
        isLoadingOlder: false,
    };
}

/**
 * Opens a conversation with someone new, before either has written anything.
 */
export function rememberPartner(partner: User): void {
    if (state.threads[partner.username] === undefined) {
        update({
            threads: {
                ...state.threads,
                [partner.username]: emptyThread(partner),
            },
        });
    }
}

async function pollInbox(): Promise<void> {
    if (document.hidden) {
        return;
    }

    try {
        const { conversations, unread, requests } = await fetchInbox('open');
        const requestsChanged =
            state.requests !== null && requests !== state.requests;

        update({
            conversations: withFirstPage(
                state.conversations,
                conversations.map((conversation) =>
                    isWatching(conversation.partner.username)
                        ? { ...conversation, ahead: 0 }
                        : conversation,
                ),
            ),
            requests,
            unread:
                unread -
                conversations
                    .filter((conversation) =>
                        isWatching(conversation.partner.username),
                    )
                    .reduce(
                        (total, conversation) => total + conversation.ahead,
                        0,
                    ),
        });

        if (requestsChanged && state.fetched !== null) {
            void loadTab('fetched');
        }
    } catch {
        // Keep the last known inbox until the next poll.
    }
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

function isWatching(username: string): boolean {
    return (threadWatchers.get(username) ?? 0) > 0;
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

async function pollThread(username: string, partner: User): Promise<void> {
    const thread = state.threads[username];
    const after = thread?.isLoaded ? (thread.messages.at(-1)?.id ?? 0) : null;

    const fresh = await request<Thread & { partner: User }>(
        ConversationMessageController.index.url(
            { partner: username },
            after === null ? undefined : { query: { after } },
        ),
    );

    const current = state.threads[username] ?? emptyThread(partner);
    const arrived = fresh.messages.length > 0 || fresh.partner_typing;

    if (arrived) {
        lastActivity.set(username, Date.now());
    }

    update({
        threads: {
            ...state.threads,
            [username]: {
                ...current,
                partner: current.isLoaded ? current.partner : fresh.partner,
                messages: mergeMessages(current.messages, fresh.messages),
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
                partner_read_through_id: fresh.partner_read_through_id,
                partner_read_at: fresh.partner_read_at,
                partner_typing: fresh.partner_typing,
                is_archived: fresh.is_archived,
                access: fresh.access,
                isLoaded: true,
            },
        },
    });
}

/**
 * Polls an open conversation quickly while it is lively and slower once it goes quiet, and not at all in a
 * hidden tab. Returns the function that stops watching.
 */
export function watchThread(partner: User): () => void {
    const username = partner.username;
    let timer: number | undefined;
    let isStopped = false;

    threadWatchers.set(username, (threadWatchers.get(username) ?? 0) + 1);

    if (state.threads[username] === undefined) {
        update({
            threads: { ...state.threads, [username]: emptyThread(partner) },
        });
    } else {
        updateThread(username, (thread) => ({
            head: thread.isLoaded ? thread.read_through_id : thread.head,
            headUntil: thread.isLoaded
                ? (thread.messages.at(-1)?.id ?? 0)
                : thread.headUntil,
        }));
    }

    const tick = async () => {
        window.clearTimeout(timer);

        if (!document.hidden) {
            try {
                await pollThread(username, partner);
            } catch {
                // Keep the conversation as it is until the next poll.
            }
        }

        if (isStopped) {
            return;
        }

        const isActive =
            Date.now() - (lastActivity.get(username) ?? 0) < activeWindow;

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

    return () => {
        isStopped = true;
        window.clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);

        const watchers = (threadWatchers.get(username) ?? 1) - 1;

        if (watchers === 0) {
            threadWatchers.delete(username);
        } else {
            threadWatchers.set(username, watchers);
        }

        scheduleInbox();
    };
}

function touchInbox(partner: User, message: Message): void {
    const conversations = state.conversations ?? [];
    const existing = conversations.find(
        (conversation) => conversation.partner.username === partner.username,
    );
    const summary: ConversationSummary = {
        id: existing?.id ?? 0,
        partner,
        last_message: message,
        last_message_at: message.created_at,
        ahead: existing?.ahead ?? 0,
        partner_read_through_id: existing?.partner_read_through_id ?? 0,
        partner_read_at: existing?.partner_read_at ?? null,
        is_archived: false,
    };

    update({
        conversations: [
            summary,
            ...conversations.filter(
                (conversation) => conversation !== existing,
            ),
        ],
        archived: withoutPartner(state.archived, partner),
    });
    updateThread(partner.username, () => ({ is_archived: false }));
}

async function deliver(partner: User, pending: PendingMessage): Promise<void> {
    const username = partner.username;

    try {
        const { message } = await request<{ message: Message }>(
            ConversationMessageController.store.url({ partner: username }),
            'POST',
            { body: pending.body, client_id: pending.client_id },
        );

        lastActivity.set(username, Date.now());

        if (state.threads[username]?.messages.length === 0) {
            void pollThread(username, partner).catch(() => undefined);
        }

        updateThread(username, (thread) => ({
            messages: mergeMessages(thread.messages, [message]),
            pending: thread.pending.filter(
                (item) => item.client_id !== pending.client_id,
            ),
            read_through_id: Math.max(thread.read_through_id, message.id),
            head: Math.max(thread.head, message.id),
        }));
        touchInbox(partner, message);
    } catch {
        updateThread(username, (thread) => ({
            pending: thread.pending.map((item) =>
                item.client_id === pending.client_id
                    ? { ...item, status: 'rejected' }
                    : item,
            ),
        }));
    }
}

/**
 * Shows the message straight away as staged, then swaps in the stored message once the server has it.
 */
export function sendMessage(partner: User, body: string): void {
    const username = partner.username;

    if (state.threads[username] === undefined) {
        update({
            threads: { ...state.threads, [username]: emptyThread(partner) },
        });
    }

    const pending: PendingMessage = {
        client_id: crypto.randomUUID(),
        body,
        created_at: new Date().toISOString(),
        status: 'staged',
    };

    lastTypingPing.delete(username);
    updateThread(username, (thread) => ({
        pending: [...thread.pending, pending],
    }));
    void deliver(partner, pending);
}

/**
 * Sends a rejected message again with its original client id, so the server stores it once at most.
 */
export function retryMessage(partner: User, clientId: string): void {
    const pending = state.threads[partner.username]?.pending.find(
        (item) => item.client_id === clientId,
    );

    if (pending === undefined) {
        return;
    }

    updateThread(partner.username, (thread) => ({
        pending: thread.pending.map((item) =>
            item.client_id === clientId ? { ...item, status: 'staged' } : item,
        ),
    }));
    void deliver(partner, { ...pending, status: 'staged' });
}

export function markThreadRead(partner: User): void {
    const username = partner.username;
    const thread = state.threads[username];
    const latest = thread?.messages.at(-1)?.id ?? 0;

    if (thread === undefined || latest <= thread.read_through_id) {
        return;
    }

    updateThread(username, () => ({ read_through_id: latest }));

    const conversations = (state.conversations ?? []).map((conversation) =>
        conversation.partner.username === username
            ? { ...conversation, ahead: 0 }
            : conversation,
    );

    update({ conversations, unread: unreadOf(conversations) });

    void request(
        ConversationReadController.update.url({ partner: username }),
        'PUT',
        { through: latest },
    ).catch(() => undefined);
}

export function pingTyping(partner: User): void {
    const username = partner.username;
    const now = Date.now();

    if (now - (lastTypingPing.get(username) ?? 0) < typingPing) {
        return;
    }

    lastTypingPing.set(username, now);
    void request(
        ConversationTypingController.update.url({ partner: username }),
        'PUT',
    ).catch(() => undefined);
}

export async function loadOlderMessages(partner: User): Promise<void> {
    const username = partner.username;
    const thread = state.threads[username];
    const oldest = thread?.messages[0]?.id;

    if (
        thread === undefined ||
        oldest === undefined ||
        !thread.has_older ||
        thread.isLoadingOlder
    ) {
        return;
    }

    updateThread(username, () => ({ isLoadingOlder: true }));

    try {
        const older = await request<Thread>(
            ConversationMessageController.index.url(
                { partner: username },
                { query: { before: oldest } },
            ),
        );

        updateThread(username, (current) => ({
            messages: mergeMessages(current.messages, older.messages),
            has_older: older.has_older,
            isLoadingOlder: false,
        }));
    } catch {
        updateThread(username, () => ({ isLoadingOlder: false }));
    }
}

function withoutPartner(
    conversations: ConversationSummary[] | null,
    partner: User,
): ConversationSummary[] | null {
    return (
        conversations?.filter(
            (conversation) =>
                conversation.partner.username !== partner.username,
        ) ?? null
    );
}

function byLatest(conversations: ConversationSummary[]): ConversationSummary[] {
    return [...conversations].sort((a, b) =>
        (b.last_message_at ?? '').localeCompare(a.last_message_at ?? ''),
    );
}

/**
 * Searches the user's conversations by the partner's name or handle, on whichever tab is open.
 */
export async function searchConversations(
    term: string,
    tab: InboxTab,
    signal: AbortSignal,
): Promise<ConversationSummary[]> {
    const response = await fetch(
        InboxController.index.url({ query: { q: term, tab } }),
        { headers: { Accept: 'application/json' }, cache: 'no-store', signal },
    );

    if (!response.ok) {
        throw new Error(`Conversation search failed: ${response.status}`);
    }

    return ((await response.json()) as InboxPage).conversations;
}

/**
 * Merges a fetched request into the open conversations, or drops it. The sender is never told about a drop.
 */
export async function settleRequest(
    partner: User,
    isMerging: boolean,
): Promise<boolean> {
    const username = partner.username;

    try {
        await request(
            isMerging
                ? ConversationMergeController.store.url({ partner: username })
                : ConversationDropController.store.url({ partner: username }),
            'POST',
        );
    } catch {
        return false;
    }

    const request_ = state.fetched?.find(
        (item) => item.partner.username === username,
    );

    update({
        fetched: withoutPartner(state.fetched, partner),
        requests: Math.max(0, (state.requests ?? 1) - 1),
        unread: Math.max(0, (state.unread ?? 1) - 1),
        ...(isMerging && request_
            ? {
                  conversations: byLatest([
                      ...(withoutPartner(state.conversations, partner) ?? []),
                      { ...request_, ahead: 0 },
                  ]),
              }
            : {}),
    });
    updateThread(username, () => ({ access: isMerging ? 'open' : 'waiting' }));

    return true;
}

/**
 * Moves the conversation between the inbox and the archive straight away, and back if the server refuses.
 */
export async function setConversationArchived(
    partner: User,
    isArchived: boolean,
): Promise<boolean> {
    const previous = {
        conversations: state.conversations,
        archived: state.archived,
        unread: state.unread,
    };
    const conversation = [
        ...(state.conversations ?? []),
        ...(state.archived ?? []),
    ].find((item) => item.partner.username === partner.username);

    if (conversation !== undefined) {
        const moved = { ...conversation, is_archived: isArchived };
        const conversations = isArchived
            ? withoutPartner(state.conversations, partner)
            : byLatest([
                  ...(withoutPartner(state.conversations, partner) ?? []),
                  moved,
              ]);
        const archived = isArchived
            ? state.archived &&
              byLatest([
                  ...(withoutPartner(state.archived, partner) ?? []),
                  moved,
              ])
            : withoutPartner(state.archived, partner);

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

    updateThread(partner.username, () => ({ is_archived: isArchived }));

    try {
        await request(
            isArchived
                ? ConversationArchiveController.store.url({
                      partner: partner.username,
                  })
                : ConversationArchiveController.destroy.url({
                      partner: partner.username,
                  }),
            isArchived ? 'POST' : 'DELETE',
        );

        return true;
    } catch {
        update(previous);
        updateThread(partner.username, () => ({ is_archived: !isArchived }));

        return false;
    }
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
        conversations: withoutPartner(state.conversations, partner),
        fetched: withoutPartner(state.fetched, partner),
        archived: withoutPartner(state.archived, partner),
        threads,
    });

    return true;
}
