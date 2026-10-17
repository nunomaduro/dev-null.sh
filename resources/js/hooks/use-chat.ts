import { usePage } from '@inertiajs/react';
import { useEffect, useSyncExternalStore } from 'react';
import {
    chatSnapshot,
    subscribeToChat,
    watchInbox,
    watchThread,
} from '@/lib/chat';
import type { ChatState, ThreadState } from '@/lib/chat';
import type { User } from '@/types';

export function useChat(): ChatState {
    return useSyncExternalStore(subscribeToChat, chatSnapshot, chatSnapshot);
}

export function useInbox(isWatching = true): ChatState['conversations'] {
    const { conversations } = useChat();

    useEffect(() => (isWatching ? watchInbox() : undefined), [isWatching]);

    return conversations;
}

export function useThread(partner: User | null): ThreadState | null {
    const { threads } = useChat();
    const username = partner?.username ?? null;

    useEffect(() => {
        if (partner === null) {
            return;
        }

        return watchThread(partner);
        // The partner object changes identity on every poll; only a different person restarts the watch.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [username]);

    return username === null ? null : (threads[username] ?? null);
}

/**
 * The chat's own count while it is polling, otherwise the count shared with every page.
 */
export function useUnreadMessages(): number {
    const { auth } = usePage().props;
    const { unread, isPollingInbox } = useChat();

    return isPollingInbox && unread !== null
        ? unread
        : auth.unreadMessagesCount;
}
