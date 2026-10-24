import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    afterEmbedLeft,
    corners,
    floorUnder,
    carriedBy,
    homingRange,
    springStiffness,
    ceilingGap,
    ceilingOver,
    compactBelow,
    defaultDockCorner,
    departureBox,
    isCompact,
    closeAction,
    cornerBox,
    defaultDockLength,
    dockMargin,
    dockSize,
    isSpringSettled,
    maxDockLength,
    minDockLength,
    dockGrabberSpace,
    nearestCorner,
    oppositeCorner,
    parseAspectRatio,
    lastSliver,
    popOutBelow,
    returnAbove,
    visibleFraction,
    resizedLength,
    restingVelocity,
    returnsByItself,
    stepSpring,
} from '../../resources/js/lib/video-dock-geometry.ts';

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };

await test('reads the aspect ratio of an embed', () => {
    assert.equal(parseAspectRatio('16/9'), 16 / 9);
    assert.equal(parseAspectRatio('9 / 16'), 9 / 16);
    assert.equal(parseAspectRatio(null), null);
    assert.equal(parseAspectRatio('wide'), null);
    assert.equal(parseAspectRatio('16/0'), null);
});

await test('starts smaller on narrow screens', () => {
    assert.equal(defaultDockLength(desktop), 360);
    assert.equal(defaultDockLength(phone), 234);
});

await test('sizes the player by its longer side', () => {
    assert.deepEqual(dockSize(400, 16 / 9, desktop), {
        width: 400,
        height: 225,
    });
    assert.deepEqual(dockSize(400, 9 / 16, desktop), {
        width: 225,
        height: 400,
    });
});

await test('keeps the player between its smallest and largest size', () => {
    assert.equal(dockSize(10, 16 / 9, desktop).width, minDockLength);
    assert.equal(dockSize(5000, 16 / 9, desktop).width, maxDockLength);
});

await test('shrinks the player to fit the screen', () => {
    const size = dockSize(maxDockLength, 16 / 9, phone);

    assert.equal(size.width, phone.width - dockMargin * 2);
    assert.equal(size.height, Math.round(size.width / (16 / 9)));
});

await test('places the player in each corner of the screen', () => {
    const size = { width: 320, height: 180 };

    assert.deepEqual(cornerBox('top-left', size, desktop), {
        x: 24,
        y: 24,
        ...size,
    });
    assert.deepEqual(cornerBox('bottom-right', size, desktop), {
        x: 1096,
        y: 668,
        ...size,
    });
});

await test('leaves room for the grabber below the player', () => {
    const size = { width: 320, height: 180 };
    const box = cornerBox('bottom-left', size, desktop);

    assert.equal(desktop.height - (box.y + box.height), dockGrabberSpace);
});

await test('rests above what is fixed to the bottom of the screen beneath it', () => {
    const size = { width: 320, height: 180 };
    const mobileNav = { left: 0, right: 1440, top: 836 };
    const chatDock = { left: 1056, right: 1416, top: 852 };

    assert.equal(cornerBox('bottom-left', size, desktop, [mobileNav]).y, 604);
    assert.equal(cornerBox('bottom-right', size, desktop, [chatDock]).y, 620);
    assert.equal(cornerBox('bottom-left', size, desktop, [chatDock]).y, 668);
});

await test('settles in the corner it is let go nearest to', () => {
    const still = { x: 0, y: 0 };

    assert.equal(nearestCorner({ x: 200, y: 200 }, still, desktop), 'top-left');
    assert.equal(
        nearestCorner({ x: 1200, y: 700 }, still, desktop),
        'bottom-right',
    );
});

await test('carries a fling on to the corner it was thrown toward', () => {
    assert.equal(
        nearestCorner({ x: 600, y: 400 }, { x: 2, y: -1 }, desktop),
        'top-right',
    );
});

await test('anchors a resize at the opposite corner', () => {
    assert.equal(oppositeCorner('top-left'), 'bottom-right');
    assert.equal(oppositeCorner('top-right'), 'bottom-left');
    assert.equal(oppositeCorner('bottom-left'), 'top-right');
    assert.equal(oppositeCorner('bottom-right'), 'top-left');
});

await test('resizes from any corner, keeping the anchor still', () => {
    const box = { x: 1104, y: 704, width: 320, height: 180 };

    assert.equal(
        resizedLength('bottom-right', box, { x: 1024, y: 704 }, 16 / 9),
        400,
    );
    assert.equal(
        resizedLength('top-left', box, { x: 1504, y: 704 }, 16 / 9),
        400,
    );
});

await test('follows the longer pull when resizing', () => {
    const box = { x: 1104, y: 704, width: 320, height: 180 };

    assert.equal(
        resizedLength('bottom-right', box, { x: 1104, y: 584 }, 16 / 9),
        (884 - 584) * (16 / 9),
    );
});

await test('measures how much of an embed is in view', () => {
    assert.equal(visibleFraction({ top: 100, height: 200 }, desktop), 1);
    assert.equal(visibleFraction({ top: 800, height: 200 }, desktop), 0.5);
    assert.equal(visibleFraction({ top: -150, height: 200 }, desktop), 0.25);
    assert.equal(visibleFraction({ top: -300, height: 200 }, desktop), 0);
    assert.equal(visibleFraction({ top: 1000, height: 200 }, desktop), 0);
    assert.equal(visibleFraction({ top: 100, height: 0 }, desktop), 0);
});

await test('needs more of an embed in view to come back than to leave it', () => {
    assert.ok(popOutBelow < returnAbove);
});

await test('floats once a quarter of its embed scrolls away, and comes back near the whole of it', () => {
    assert.equal(popOutBelow, 0.75);
    assert.equal(returnAbove, 0.9);
    assert.equal(lastSliver, 0.25);
    assert.ok(lastSliver < popOutBelow);
});

await test('counts a tall embed as all showing once it fills the room, so it can come back', () => {
    const tall = { top: -100, height: 1600 };

    // Taller than the screen, it is never more than the screen's worth in view.
    assert.equal(visibleFraction(tall, desktop), 1);
    assert.ok(visibleFraction(tall, desktop) >= returnAbove);
    assert.equal(visibleFraction({ top: 0, height: 1600 }, desktop, 100), 1);
    // Scrolled mostly away, it still leaves.
    assert.ok(
        visibleFraction({ top: -1500, height: 1600 }, desktop) < lastSliver,
    );
});

const settle = (
    from: { x: number; y: number; width: number; height: number },
    target: (frame: number) => {
        x: number;
        y: number;
        width: number;
        height: number;
    },
    velocity = restingVelocity,
) => {
    let spring = { position: from, velocity };

    for (let frame = 0; frame < 600; frame++) {
        spring = stepSpring(spring, target(frame), 1 / 60);

        if (isSpringSettled(spring, target(frame))) {
            return { spring, frames: frame + 1 };
        }
    }

    return { spring, frames: Infinity };
};

await test('springs the player to its target and comes to rest there', () => {
    const target = { x: 1096, y: 668, width: 320, height: 180 };
    const { spring, frames } = settle(
        { x: 200, y: 300, width: 600, height: 338 },
        () => target,
    );

    assert.ok(frames < 90);
    assert.ok(Math.abs(spring.position.x - target.x) < 0.5);
    assert.ok(Math.abs(spring.position.width - target.width) < 0.5);
});

await test('lands on an embed that is still scrolling, not where it was', () => {
    const { spring, frames } = settle(
        { x: 1096, y: 668, width: 320, height: 180 },
        (frame) => ({
            x: 200,
            y: 400 - Math.min(frame, 20) * 10,
            width: 600,
            height: 338,
        }),
    );

    assert.ok(frames < Infinity);
    assert.ok(Math.abs(spring.position.y - 200) < 0.5);
});

await test('keeps the speed it set off with', () => {
    const from = { x: 200, y: 300, width: 600, height: 338 };
    const still = stepSpring(
        { position: from, velocity: restingVelocity },
        from,
        1 / 60,
    );
    const moving = stepSpring(
        { position: from, velocity: { ...restingVelocity, y: -1200 } },
        from,
        1 / 60,
    );

    assert.equal(still.position.y, 300);
    assert.ok(moving.position.y < 290);
});

await test('is not at rest while it is still moving', () => {
    const box = { x: 0, y: 0, width: 320, height: 180 };

    assert.ok(
        isSpringSettled({ position: box, velocity: restingVelocity }, box),
    );
    assert.ok(
        !isSpringSettled(
            { position: box, velocity: { ...restingVelocity, x: 300 } },
            box,
        ),
    );
    assert.ok(
        !isSpringSettled(
            { position: { ...box, x: 5 }, velocity: restingVelocity },
            box,
        ),
    );
});

await test('closing hands the video back to the embed it came from while it is on screen', () => {
    assert.equal(closeAction(1), 'return');
    assert.equal(closeAction(lastSliver), 'return');
});

await test('closing stops a video whose embed is scrolled away or gone', () => {
    assert.equal(closeAction(lastSliver - 0.01), 'stop');
    assert.equal(closeAction(0), 'stop');
    assert.equal(closeAction(null), 'stop');
});

await test('only a video that floated by itself comes back by itself', () => {
    assert.equal(returnsByItself(true, false), true);
    assert.equal(returnsByItself(true, true), false);
    assert.equal(returnsByItself(false, false), false);
    assert.equal(returnsByItself(false, true), false);
});

await test('floats on when its embed leaves while it plays and picture-in-picture is automatic', () => {
    assert.equal(afterEmbedLeft(true, false, false), 'float');
    assert.equal(afterEmbedLeft(true, false, true), 'float');
});

await test('goes back to its embed or stops when picture-in-picture is not automatic', () => {
    assert.equal(afterEmbedLeft(false, false, true), 'return');
    assert.equal(afterEmbedLeft(false, false, false), 'stop');
});

await test('never floats a paused or finished video when its embed leaves', () => {
    assert.equal(afterEmbedLeft(true, true, false), 'stop');
    assert.equal(afterEmbedLeft(true, true, true), 'return');
});

await test('starts in the top right on a phone, out of the way of the nav and the thumb', () => {
    assert.ok(isCompact(phone));
    assert.equal(defaultDockCorner(phone), 'top-right');
    assert.equal(
        defaultDockCorner({ width: compactBelow - 1, height: 900 }),
        'top-right',
    );
});

await test('starts in the bottom right on a wide screen', () => {
    assert.ok(!isCompact(desktop));
    assert.equal(defaultDockCorner(desktop), 'bottom-right');
    assert.equal(
        defaultDockCorner({ width: compactBelow, height: 900 }),
        'bottom-right',
    );
});

await test('a video scrolled past the top slides back in from the top edge', () => {
    const box = departureBox(
        { x: 200, y: -900, width: 600, height: 400 },
        desktop,
    );

    assert.deepEqual(box, { x: 200, y: -300, width: 600, height: 400 });
    assert.equal(box.y + box.height, 400 * lastSliver);
});

await test('a video scrolled past the bottom slides back in from the bottom edge', () => {
    const box = departureBox(
        { x: 200, y: 2000, width: 600, height: 400 },
        desktop,
    );

    assert.equal(box.y, desktop.height - 400 * lastSliver);
});

await test('a video still in view sets off from where it is', () => {
    const rect = { x: 200, y: 300, width: 600, height: 400 };

    assert.deepEqual(departureBox(rect, desktop), rect);
});

const header = { left: 400, right: 1100, bottom: 60 };

await test('finds the header stuck over a stretch of the page', () => {
    assert.equal(ceilingOver(500, 900, [header]), 60);
    assert.equal(ceilingOver(1150, 1400, [header]), 0);
    assert.equal(ceilingOver(500, 900, []), 0);
    assert.equal(
        ceilingOver(500, 900, [header, { left: 0, right: 1440, bottom: 90 }]),
        90,
    );
});

await test('rests below a header stuck over its top corner', () => {
    const size = { width: 300, height: 169 };
    const phoneHeader = { left: 0, right: phone.width, bottom: 56 };

    assert.equal(
        cornerBox('top-right', size, phone, [], [phoneHeader]).y,
        56 + ceilingGap,
    );
    assert.equal(cornerBox('top-right', size, desktop, [], [header]).y, 24);
});

await test('counts what has scrolled under a header as out of view', () => {
    assert.equal(visibleFraction({ top: 0, height: 200 }, desktop, 60), 0.7);
    assert.equal(visibleFraction({ top: -100, height: 200 }, desktop, 60), 0.2);
    assert.equal(visibleFraction({ top: 100, height: 200 }, desktop, 60), 1);
});

await test('a video scrolled under a header slides back out from beneath it', () => {
    const box = departureBox(
        { x: 420, y: -900, width: 600, height: 400 },
        desktop,
        60,
    );

    assert.equal(box.y + box.height, 60 + 400 * lastSliver);
});

await test('pulls harder the closer it gets when homing in on an embed', () => {
    assert.equal(springStiffness(0, true), springStiffness(0, false) * 5);
    assert.ok(springStiffness(20, true) > springStiffness(60, true));
    assert.equal(springStiffness(homingRange, true), springStiffness(0, false));
    assert.equal(springStiffness(500, true), springStiffness(0, false));
});

await test('homing in settles sooner than a plain spring', () => {
    const from = { x: 1096, y: 668, width: 320, height: 180 };
    const target = { x: 200, y: 300, width: 600, height: 338 };
    const framesToSettle = (isHoming: boolean) => {
        let spring = { position: from, velocity: restingVelocity };

        for (let frame = 1; frame <= 600; frame++) {
            spring = stepSpring(spring, target, 1 / 60, isHoming);

            if (isSpringSettled(spring, target)) {
                return frame;
            }
        }

        return Infinity;
    };

    assert.ok(framesToSettle(true) < framesToSettle(false));
});

await test('moves with an embed being scrolled', () => {
    assert.deepEqual(
        carriedBy(
            { x: 900, y: 500, width: 320, height: 180 },
            { x: 200, y: 400, width: 600, height: 338 },
            { x: 200, y: 340, width: 600, height: 338 },
        ),
        { x: 900, y: 440, width: 320, height: 180 },
    );
});

await test('lands on an embed that keeps scrolling, instead of chasing it forever', () => {
    const homeIn = (isCarried: boolean) => {
        let previous = { x: 200, y: 600, width: 600, height: 338 };
        let spring = {
            position: { x: 1096, y: 668, width: 320, height: 180 },
            velocity: restingVelocity,
        };

        for (let frame = 1; frame <= 600; frame++) {
            // The page never stops scrolling while it flies home.
            const target = { ...previous, y: previous.y - 15 };
            const position = isCarried
                ? carriedBy(spring.position, previous, target)
                : spring.position;

            spring = stepSpring(
                { position, velocity: spring.velocity },
                target,
                1 / 60,
                true,
            );

            if (isSpringSettled(spring, target)) {
                return frame;
            }

            previous = target;
        }

        return Infinity;
    };

    assert.ok(homeIn(true) < 60);
    assert.equal(homeIn(false), Infinity);
});

await test('counts what is behind a bar fixed to the bottom as out of view', () => {
    assert.equal(
        visibleFraction({ top: 600, height: 200 }, desktop, 0, 700),
        0.5,
    );
    assert.equal(
        visibleFraction({ top: 700, height: 200 }, desktop, 0, 700),
        0,
    );
});

await test('finds the bar fixed to the bottom under a stretch of the page', () => {
    const mobileNav = { left: 0, right: 390, top: 780 };

    assert.equal(floorUnder(0, 390, [mobileNav], phone), 780);
    assert.equal(floorUnder(400, 500, [mobileNav], phone), phone.height);
});

await test('knows each corner once', () => {
    assert.deepEqual(corners, [
        'top-left',
        'top-right',
        'bottom-left',
        'bottom-right',
    ]);
});
