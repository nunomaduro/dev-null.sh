import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    acceptedPins,
    reactionsAfterOf,
    withPin,
    withPinRestored,
    withoutPin,
    withShownReactions,
} from '../../resources/js/lib/pins.ts';
import type { Message } from '../../resources/js/types';

const message = (id: number, reactions: Message['reactions'] = []) =>
    ({
        id,
        user_id: 'ana',
        client_id: `c${id}`,
        body: '',
        reactions,
    }) as Message;

const ids = (messages: Message[]) => messages.map(({ id }) => id);

await test('takes a list as new as the one shown when nothing is being pinned', () => {
    const pins = acceptedPins(
        { pinned: [message(1)], pinnedVersion: 4 },
        [message(2), message(1)],
        4,
        false,
    );

    assert.deepEqual(ids(pins?.pinned ?? []), [2, 1]);
    assert.equal(pins?.pinnedVersion, 4);
});

await test('takes the first list a thread gets', () => {
    assert.equal(
        acceptedPins(
            { pinned: [], pinnedVersion: null },
            [message(1)],
            2,
            false,
        )?.pinnedVersion,
        2,
    );
});

await test('drops a list while the user is pinning or unpinning', () => {
    assert.equal(
        acceptedPins({ pinned: [], pinnedVersion: 1 }, [message(1)], 9, true),
        null,
    );
});

await test('drops a list older than the one shown, like a poll sent before a pin that lands after it', () => {
    assert.equal(
        acceptedPins({ pinned: [message(1)], pinnedVersion: 7 }, [], 6, false),
        null,
    );
});

await test('keeps the list when the server sends none, as nothing changed', () => {
    assert.equal(
        acceptedPins(
            { pinned: [message(1)], pinnedVersion: 3 },
            null,
            3,
            false,
        ),
        null,
    );
});

await test('asks for the changes after the thread version while the pins are current', () => {
    assert.equal(reactionsAfterOf(8, 8), 8);
});

await test('asks again from the pins once a dropped list leaves them behind', () => {
    assert.equal(reactionsAfterOf(9, 6), 6);
});

await test('asks from the thread version when there are no pins to keep current', () => {
    assert.equal(reactionsAfterOf(5, null), 5);
    assert.equal(reactionsAfterOf(null, 5), null);
});

await test('keeps the reactions shown on messages the user is reacting to', () => {
    const shown = [message(1, [{ user_id: 'me', emoji: '👍' }]), message(2)];
    const incoming = [
        message(1),
        message(2, [{ user_id: 'ben', emoji: '🎉' }]),
    ];
    const kept = withShownReactions(incoming, shown, (id) => id === 1);

    assert.deepEqual(kept[0]?.reactions, [{ user_id: 'me', emoji: '👍' }]);
    assert.deepEqual(kept[1]?.reactions, [{ user_id: 'ben', emoji: '🎉' }]);
});

await test('hands back the same list when no reaction is held', () => {
    const incoming = [message(1)];

    assert.equal(
        withShownReactions(incoming, [], () => false),
        incoming,
    );
});

await test('pins a message first, once', () => {
    const pinned = withPin([message(1)], message(2));

    assert.deepEqual(ids(pinned), [2, 1]);
    assert.notEqual(pinned[0]?.pinned_at ?? null, null);
    assert.equal(withPin(pinned, message(2)), pinned);
});

await test('unpins a message and puts it back where it was', () => {
    const pinned = [message(3), message(2), message(1)];
    const unpinned = withoutPin(pinned, message(2));

    assert.deepEqual(ids(unpinned), [3, 1]);
    assert.deepEqual(ids(withPinRestored(unpinned, message(2), 1)), [3, 2, 1]);
    assert.deepEqual(ids(withPinRestored([], message(2), 1)), [2]);
});
