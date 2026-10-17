import { useSyncExternalStore } from 'react';
import { readStorage, writeStorage } from '@/lib/storage';

export type ChatDock = {
    isDocked: boolean;
    isExpanded: boolean;
    partner: string | null;
};

const storageKey = 'chat-dock';
const undocked: ChatDock = {
    isDocked: false,
    isExpanded: false,
    partner: null,
};
const listeners = new Set<() => void>();
let dock: ChatDock | null = null;

function read(): ChatDock {
    if (dock !== null) {
        return dock;
    }

    try {
        const stored = JSON.parse(
            readStorage(storageKey) || 'null',
        ) as Partial<ChatDock> | null;

        dock = {
            isDocked: stored?.isDocked === true,
            isExpanded: stored?.isExpanded === true,
            partner:
                typeof stored?.partner === 'string' ? stored.partner : null,
        };
    } catch {
        dock = undocked;
    }

    return dock;
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    const onStorage = (event: StorageEvent) => {
        if (event.key === storageKey) {
            dock = null;
            listener();
        }
    };

    window.addEventListener('storage', onStorage);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
    };
}

export function updateChatDock(change: Partial<ChatDock>): void {
    dock = { ...read(), ...change };
    writeStorage(storageKey, dock.isDocked ? JSON.stringify(dock) : '');
    listeners.forEach((listener) => listener());
}

/**
 * Docking starts minimized, so turning it on never covers the page the user is about to read.
 */
export function setChatDocked(isDocked: boolean): void {
    updateChatDock(
        isDocked ? { isDocked, isExpanded: false } : { ...undocked },
    );
}

export function useChatDock(): ChatDock {
    return useSyncExternalStore(subscribe, read, () => undocked);
}
