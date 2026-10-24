/**
 * A chain of animation frames that runs while there is something to do and sleeps otherwise. There is only ever one
 * frame waiting, however often it is woken, even from inside a frame, and once stopped it never runs again, so a
 * closed player can't keep working in the background.
 */
export type FrameLoop = { wake: () => void; stop: () => void };

/**
 * `onFrame` says whether it needs another frame straight away. It is told when the loop is waking from sleep, so the
 * first frame after a rest isn't measured from the last one before it.
 */
export function createFrameLoop(
    onFrame: (now: number, isWaking: boolean) => boolean,
    schedule: (callback: (now: number) => void) => number = (callback) =>
        requestAnimationFrame(callback),
    cancel: (request: number) => void = (request) =>
        cancelAnimationFrame(request),
): FrameLoop {
    let request = 0;
    let isAsleep = true;
    let isStopped = false;

    const tick = (now: number) => {
        request = 0;

        if (isStopped) {
            return;
        }

        const isWaking = isAsleep;

        isAsleep = false;

        const needsAnother = onFrame(now, isWaking);

        if (isStopped || request !== 0) {
            return;
        }

        if (needsAnother) {
            request = schedule(tick);
        } else {
            isAsleep = true;
        }
    };

    return {
        wake: () => {
            if (!isStopped && request === 0) {
                request = schedule(tick);
            }
        },
        stop: () => {
            isStopped = true;

            if (request !== 0) {
                cancel(request);
                request = 0;
            }
        },
    };
}
