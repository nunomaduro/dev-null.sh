import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createFrameLoop } from '../../resources/js/lib/frame-loop.ts';

/**
 * Animation frames run by hand: `frame()` runs every callback waiting for the next frame, as the browser would.
 */
const frames = () => {
    let next = 1;
    let time = 0;
    const waiting = new Map<number, (now: number) => void>();

    return {
        schedule: (callback: (now: number) => void) => {
            waiting.set(next, callback);

            return next++;
        },
        cancel: (request: number) => {
            waiting.delete(request);
        },
        frame: () => {
            const due = [...waiting.values()];

            waiting.clear();
            time += 16;
            due.forEach((callback) => callback(time));
        },
        get waiting() {
            return waiting.size;
        },
    };
};

await test('sleeps until woken', () => {
    const clock = frames();
    let runs = 0;

    createFrameLoop(() => ++runs < 0, clock.schedule, clock.cancel);
    clock.frame();

    assert.equal(runs, 0);
    assert.equal(clock.waiting, 0);
});

await test('keeps running while there is something to do, then sleeps', () => {
    const clock = frames();
    let runs = 0;
    const loop = createFrameLoop(() => ++runs < 3, clock.schedule, clock.cancel);

    loop.wake();

    for (let frame = 0; frame < 10; frame++) {
        clock.frame();
    }

    assert.equal(runs, 3);
    assert.equal(clock.waiting, 0);
});

await test('waits on one frame at a time, however often it is woken', () => {
    const clock = frames();
    const loop = createFrameLoop(() => true, clock.schedule, clock.cancel);

    loop.wake();
    loop.wake();
    loop.wake();

    assert.equal(clock.waiting, 1);
});

await test('stays one chain when woken from inside its own frame', () => {
    const clock = frames();
    let runs = 0;
    const loop = createFrameLoop(
        () => {
            runs++;
            // A frame that changes the store wakes the loop, and still asks for the next frame itself.
            loop.wake();

            return true;
        },
        clock.schedule,
        clock.cancel,
    );

    loop.wake();

    for (let frame = 0; frame < 5; frame++) {
        clock.frame();
        assert.equal(clock.waiting, 1);
    }

    assert.equal(runs, 5);
});

await test('never runs again once stopped, even after being woken from inside a frame', () => {
    const clock = frames();
    let runs = 0;
    const loop = createFrameLoop(
        () => {
            runs++;
            loop.wake();

            return true;
        },
        clock.schedule,
        clock.cancel,
    );

    loop.wake();
    clock.frame();
    loop.stop();
    loop.wake();

    for (let frame = 0; frame < 5; frame++) {
        clock.frame();
    }

    assert.equal(runs, 1);
    assert.equal(clock.waiting, 0);
});

await test('stops for good when a frame stops it', () => {
    const clock = frames();
    let runs = 0;
    const loop = createFrameLoop(
        () => {
            runs++;
            loop.stop();

            return true;
        },
        clock.schedule,
        clock.cancel,
    );

    loop.wake();

    for (let frame = 0; frame < 5; frame++) {
        clock.frame();
    }

    assert.equal(runs, 1);
    assert.equal(clock.waiting, 0);
});

await test('tells a frame when it wakes from sleep', () => {
    const clock = frames();
    const waking: boolean[] = [];
    let runs = 0;
    const loop = createFrameLoop(
        (_now, isWaking) => {
            waking.push(isWaking);

            return ++runs < 2;
        },
        clock.schedule,
        clock.cancel,
    );

    loop.wake();
    clock.frame();
    clock.frame();
    loop.wake();
    clock.frame();

    assert.deepEqual(waking, [true, false, true]);
});
