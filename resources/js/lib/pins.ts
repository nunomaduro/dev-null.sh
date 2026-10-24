import type { Message } from '@/types';

/**
 * An org's pinned messages as a thread shows them, with the reactions version they are current with. Pinning and
 * unpinning move that version, so a list sent with an older one is from before a change the thread already shows.
 */
export type Pins = { pinned: Message[]; pinnedVersion: number | null };

/**
 * The pinned messages the server sent along with the reactions version it read just before them, unless the user is
 * pinning or unpinning in the org right now, as their own request hands back a fresher list, or the list is older than
 * the one shown. Null when the thread keeps what it has.
 */
export function acceptedPins(
    current: Pins,
    pinned: Message[] | null,
    version: number,
    isPinning: boolean,
): Pins | null {
    if (
        pinned === null ||
        isPinning ||
        (current.pinnedVersion !== null && version < current.pinnedVersion)
    ) {
        return null;
    }

    return { pinned, pinnedVersion: version };
}

/**
 * The reactions version a poll asks for the changes after. Once a poll's pinned messages were dropped they lag behind
 * the thread, so the next poll asks from theirs and gets them again. Changes sent twice are whole lists per message,
 * so taking them again changes nothing.
 */
export function reactionsAfterOf(
    reactionsVersion: number | null,
    pinnedVersion: number | null,
): number | null {
    return reactionsVersion === null || pinnedVersion === null
        ? reactionsVersion
        : Math.min(reactionsVersion, pinnedVersion);
}

/**
 * Keeps the reactions shown on the messages the user is reacting to right now, or reacted to since the list was sent:
 * their own request hands back fresher ones.
 */
export function withShownReactions(
    incoming: Message[],
    shown: Message[],
    isHeld: (id: number) => boolean,
): Message[] {
    if (!incoming.some((message) => isHeld(message.id))) {
        return incoming;
    }

    const reactions = new Map(
        shown.map((message) => [message.id, message.reactions]),
    );

    return incoming.map((message) =>
        isHeld(message.id) && reactions.has(message.id)
            ? { ...message, reactions: reactions.get(message.id) }
            : message,
    );
}

export function withPin(pinned: Message[], message: Message): Message[] {
    return pinned.some((item) => item.id === message.id)
        ? pinned
        : [{ ...message, pinned_at: new Date().toISOString() }, ...pinned];
}

export function withoutPin(pinned: Message[], message: Message): Message[] {
    return pinned.filter((item) => item.id !== message.id);
}

/**
 * Puts an unpinned message back where it was, when the server refused to unpin it.
 */
export function withPinRestored(
    pinned: Message[],
    message: Message,
    index: number,
): Message[] {
    return pinned.some((item) => item.id === message.id)
        ? pinned
        : pinned.toSpliced(Math.min(index, pinned.length), 0, message);
}
