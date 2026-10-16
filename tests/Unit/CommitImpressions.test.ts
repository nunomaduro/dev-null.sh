/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    CommitImpressionClient,
    hasEnoughCommitVisibility,
    usableCommitImpressionViewport,
    type CommitImpressionBatch,
    type ImpressionEnvironment,
    type ImpressionRect,
} from '../../resources/js/lib/commit-impressions.ts';

const SECOND = 1_000;
const REPEAT_WINDOW = 30 * 60 * SECOND;
const TOKEN_EXPIRES_AT = 24 * 60 * 60;
const tokenFor = (commitId: string) => `server-token-${commitId}`;
const commitIds = (batch: CommitImpressionBatch) =>
    batch.impressions.map(({ commit_id }) => commit_id);
type Timer = ReturnType<typeof setTimeout>;
type DeliveryResult = 'accepted' | 'retry' | 'discard';
type Event = Parameters<ImpressionEnvironment['listen']>[0];

function rect(top = 0, height = 200, left = 0, width = 400): ImpressionRect {
    return {
        top,
        bottom: top + height,
        left,
        right: left + width,
        width,
        height,
    };
}

class FakeEnvironment implements ImpressionEnvironment {
    time = 0;
    visible = true;
    viewportRect = rect(0, 800, 0, 1_000);
    timers = new Map<number, { at: number; callback: () => void }>();
    rects = new Map<Element, ImpressionRect>();
    listeners = new Map<Event, Set<() => void>>();
    observed = new Set<Element>();
    deliveries: {
        batch: CommitImpressionBatch;
        keepalive: boolean;
        signal: AbortSignal;
    }[] = [];
    outcomes: (DeliveryResult | Error)[] = [];
    observerCount = 0;
    nextTimer = 1;
    nextUuid = 1;
    onIntersection: ((targets: Element[]) => void) | null = null;
    pendingDelivery: Promise<DeliveryResult> | null = null;

    now = () => this.time;
    isVisible = () => this.visible;
    viewport = () => this.viewportRect;
    rect = (target: Element) => this.rects.get(target)!;
    uuid = () => `batch-${this.nextUuid++}`;

    setTimeout = (callback: () => void, delay: number): Timer => {
        const id = this.nextTimer++;
        this.timers.set(id, { at: this.time + delay, callback });
        return id as unknown as Timer;
    };

    clearTimeout = (timer: Timer) => {
        this.timers.delete(timer as unknown as number);
    };

    createObserver = (onChange: (targets: Element[]) => void) => {
        this.observerCount += 1;
        this.onIntersection = onChange;
        return {
            observe: (target: Element) => this.observed.add(target),
            unobserve: (target: Element) => this.observed.delete(target),
            disconnect: () => this.observed.clear(),
        };
    };

    listen = (event: Event, callback: () => void) => {
        const callbacks = this.listeners.get(event) ?? new Set();
        callbacks.add(callback);
        this.listeners.set(event, callbacks);
        return () => callbacks.delete(callback);
    };

    deliver: ImpressionEnvironment['deliver'] = async (batch, options) => {
        this.deliveries.push({ batch, ...options });
        if (this.pendingDelivery !== null) {
            return this.pendingDelivery;
        }
        const outcome = this.outcomes.shift() ?? 'accepted';
        if (outcome instanceof Error) {
            throw outcome;
        }
        return outcome;
    };

    target(bounds = rect()): Element {
        const target = {} as Element;
        this.rects.set(target, bounds);
        return target;
    }

    move(target: Element, bounds: ImpressionRect): void {
        this.rects.set(target, bounds);
        this.onIntersection?.([target]);
    }

    emit(event: Event): void {
        this.listeners.get(event)?.forEach((callback) => callback());
    }

    advance(duration: number): void {
        const end = this.time + duration;
        while (true) {
            const next = [...this.timers.entries()]
                .filter(([, timer]) => timer.at <= end)
                .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (next === undefined) {
                break;
            }
            this.time = next[1].at;
            this.timers.delete(next[0]);
            next[1].callback();
        }
        this.time = end;
    }
}

async function settle(): Promise<void> {
    await new Promise<void>((resolve) => setImmediate(resolve));
}

function setup() {
    const environment = new FakeEnvironment();
    return { environment, client: new CommitImpressionClient(environment) };
}

void test('requires a server-issued token and expiry before installing tracking', () => {
    const { environment, client } = setup();
    client.observe(
        'missing-token',
        environment.target(),
        null,
        TOKEN_EXPIRES_AT,
    );
    client.observe('empty-token', environment.target(), '', TOKEN_EXPIRES_AT);
    client.observe('missing-expiry', environment.target(), 'token', null);
    client.observe('invalid-expiry', environment.target(), 'token', NaN);
    client.observe('expired', environment.target(), 'token', 0);
    environment.advance(10 * SECOND);
    environment.emit('pagehide');
    assert.equal(environment.observerCount, 0);
    assert.equal(environment.listeners.size, 0);
    assert.equal(environment.timers.size, 0);
    assert.equal(environment.deliveries.length, 0);
    client.dispose();
});

void test('expiry during the visibility second does not suppress a newly served token', () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, 'expiring-token', 1);
    environment.advance(6 * SECOND);
    assert.equal(environment.deliveries.length, 0);

    client.observe('commit', target, 'fresh-token', TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    assert.deepEqual(environment.deliveries[0].batch.impressions, [
        { commit_id: 'commit', token: 'fresh-token' },
    ]);
    client.dispose();
});

void test('an already expired card accepts a newly served token without waiting for the repeat window', () => {
    const { environment, client } = setup();
    const target = environment.target();
    environment.time = 10 * SECOND;
    client.observe('commit', target, 'expired-token', 10);
    environment.advance(6 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    client.observe('commit', target, 'fresh-token', TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    assert.deepEqual(environment.deliveries[0].batch.impressions, [
        { commit_id: 'commit', token: 'fresh-token' },
    ]);
    client.dispose();
});

void test('a token expiring during batching does not suppress a freshly served token', async () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, 'old-token', 2);
    environment.advance(6 * SECOND);
    await settle();
    assert.equal(environment.deliveries.length, 1);

    client.observe('commit', target, 'fresh-token', TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    await settle();
    assert.deepEqual(environment.deliveries[1].batch.impressions, [
        { commit_id: 'commit', token: 'fresh-token' },
    ]);
    client.dispose();
});

void test('requires at least 50% of the content area, capped to the viewport for tall commits', () => {
    const viewport = rect(0, 800, 0, 1_000);
    assert.equal(hasEnoughCommitVisibility(rect(700), viewport), true);
    assert.equal(hasEnoughCommitVisibility(rect(701), viewport), false);
    assert.equal(hasEnoughCommitVisibility(rect(0, 4_000), viewport), true);
    assert.equal(hasEnoughCommitVisibility(rect(401, 4_000), viewport), false);
    assert.equal(hasEnoughCommitVisibility(rect(0, 0), viewport), false);
    assert.equal(
        hasEnoughCommitVisibility(rect(0, 200, 1_001), viewport),
        false,
    );
});

void test('subtracts pinned top and bottom occluders without counting unpinned or offscreen headers', () => {
    const viewport = rect(0, 800, 0, 1_000);
    assert.deepEqual(
        usableCommitImpressionViewport(viewport, [
            { edge: 'top', rect: rect(0, 56) },
            { edge: 'bottom', rect: rect(736, 64) },
            { edge: 'top', rect: rect(100, 100) },
            { edge: 'bottom', rect: rect(500, 100) },
            { edge: 'top', rect: rect(-100, 50) },
            { edge: 'top', rect: rect(0, 300, 1_001) },
            { edge: 'bottom', rect: rect(800, 0) },
        ]),
        rect(56, 680, 0, 1_000),
    );
    assert.equal(
        usableCommitImpressionViewport(viewport, [
            { edge: 'top', rect: rect(-20, 76) },
            { edge: 'top', rect: rect(0, 40) },
        ]).top,
        56,
    );
    assert.equal(
        usableCommitImpressionViewport(viewport, [
            { edge: 'top', rect: rect(0, 800) },
        ]).height,
        0,
    );
});

void test('content hidden beneath a sticky header or bottom navigation does not qualify', () => {
    const { environment, client } = setup();
    environment.viewportRect = usableCommitImpressionViewport(
        environment.viewportRect,
        [
            { edge: 'top', rect: rect(0, 56) },
            { edge: 'bottom', rect: rect(736, 64) },
        ],
    );
    const top = environment.target(rect(0, 100));
    const bottom = environment.target(rect(740, 60));
    client.observe('top', top, tokenFor('top'), TOKEN_EXPIRES_AT);
    client.observe('bottom', bottom, tokenFor('bottom'), TOKEN_EXPIRES_AT);
    environment.advance(2 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    environment.move(top, rect(6, 100));
    environment.move(bottom, rect(706, 60));
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.deepEqual(commitIds(environment.deliveries[0].batch), [
        'top',
        'bottom',
    ]);
    client.dispose();
});

void test('opening a blocking modal resets the exposure timer until the feed is visible again', () => {
    const { environment, client } = setup();
    client.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(999);
    environment.visible = false;
    environment.emit('modalchange');
    environment.advance(10 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    environment.visible = true;
    environment.emit('modalchange');
    environment.advance(999);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 0);
    environment.move([...environment.observed][0], rect());
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.deepEqual(commitIds(environment.deliveries[0].batch), ['commit']);
    client.dispose();
});

void test('requires one continuous second and resets after scrolling below the threshold', () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, tokenFor('commit'), TOKEN_EXPIRES_AT);
    environment.advance(999);
    environment.move(target, rect(701));
    environment.advance(SECOND);
    environment.move(target, rect(700));
    environment.advance(999);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 0);
    environment.move(target, rect(700));
    environment.advance(SECOND);
    environment.emit('visibilitychange');
    environment.advance(5 * SECOND);
    assert.deepEqual(commitIds(environment.deliveries[0].batch), ['commit']);
    client.dispose();
});

void test('background tabs reset visibility time and never qualify while hidden', () => {
    const { environment, client } = setup();
    client.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(900);
    environment.visible = false;
    environment.emit('visibilitychange');
    environment.advance(10 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    environment.visible = true;
    environment.emit('visibilitychange');
    environment.advance(999);
    environment.visible = false;
    environment.emit('visibilitychange');
    assert.equal(environment.deliveries.length, 0);
    environment.visible = true;
    environment.emit('visibilitychange');
    environment.advance(SECOND);
    environment.visible = false;
    environment.emit('visibilitychange');
    assert.equal(environment.deliveries.length, 1);
    assert.equal(environment.deliveries[0].keepalive, true);
    client.dispose();
});

void test('shares one observer and flushes at 20 distinct commits', () => {
    const { environment, client } = setup();
    for (let index = 0; index < 20; index += 1) {
        client.observe(
            `commit-${index}`,
            environment.target(),
            tokenFor(`commit-${index}`),
            TOKEN_EXPIRES_AT,
        );
    }
    client.observe(
        'commit-0',
        environment.target(),
        tokenFor('commit-0'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(SECOND);
    assert.equal(environment.observerCount, 1);
    assert.equal(environment.deliveries.length, 1);
    assert.equal(commitIds(environment.deliveries[0].batch).length, 20);
    assert.equal(new Set(commitIds(environment.deliveries[0].batch)).size, 20);
    client.dispose();
});

void test('flushes small batches five seconds after the first qualification', () => {
    const { environment, client } = setup();
    client.observe(
        'first',
        environment.target(),
        tokenFor('first'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(SECOND);
    client.observe(
        'second',
        environment.target(),
        tokenFor('second'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(4_999);
    assert.equal(environment.deliveries.length, 0);
    environment.advance(1);
    assert.deepEqual(commitIds(environment.deliveries[0].batch), [
        'first',
        'second',
    ]);
    client.dispose();
});

void test('counts once per continuous exposure and only permits fresh exposures after 30 minutes', async () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, tokenFor('commit'), TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    await settle();
    environment.advance(REPEAT_WINDOW);
    assert.equal(environment.deliveries.length, 1);
    assert.equal(environment.timers.size, 0);
    environment.move(target, rect(801));
    environment.move(target, rect());
    environment.advance(6 * SECOND);
    await settle();
    assert.equal(environment.deliveries.length, 2);
    environment.move(target, rect(801));
    environment.move(target, rect());
    environment.advance(6 * SECOND);
    assert.equal(environment.deliveries.length, 2);
    assert.equal(environment.timers.size, 0);
    client.dispose();
});

void test('accepts the exact 30-minute boundary without extending it for rejected repeats', async () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, tokenFor('commit'), TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    await settle();
    environment.time = REPEAT_WINDOW - SECOND;
    environment.move(target, rect(801));
    environment.move(target, rect());
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 1);
    environment.move(target, rect());
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 2);
    client.dispose();
});

void test('scroll geometry handles tall targets when native intersection ratios miss the capped threshold', () => {
    const { environment, client } = setup();
    const target = environment.target(rect(401, 4_000));
    client.observe('tall', target, tokenFor('tall'), TOKEN_EXPIRES_AT);
    environment.advance(2 * SECOND);
    environment.rects.set(target, rect(400, 4_000));
    environment.emit('scroll');
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.deepEqual(commitIds(environment.deliveries[0].batch), ['tall']);
    client.dispose();
});

void test('rechecks geometry when the timer expires even without an observer callback', () => {
    const { environment, client } = setup();
    const target = environment.target();
    client.observe('commit', target, tokenFor('commit'), TOKEN_EXPIRES_AT);
    environment.advance(999);
    environment.rects.set(target, rect(801));
    environment.advance(10 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    assert.equal(environment.timers.size, 0);
    client.dispose();
});

void test('layout changes refresh usable visibility even without a native intersection threshold crossing', () => {
    const { environment, client } = setup();
    environment.viewportRect = usableCommitImpressionViewport(
        rect(0, 800, 0, 1_000),
        [{ edge: 'bottom', rect: rect(736, 64) }],
    );
    const target = environment.target(rect(680));
    client.observe('shifted', target, tokenFor('shifted'), TOKEN_EXPIRES_AT);
    environment.advance(SECOND);

    environment.rects.set(target, rect(620));
    environment.emit('layoutchange');
    environment.advance(SECOND);
    environment.emit('pagehide');

    assert.deepEqual(commitIds(environment.deliveries[0].batch), ['shifted']);
    client.dispose();
});

void test('layout shifts below usable visibility reset a continuous exposure', () => {
    const { environment, client } = setup();
    environment.viewportRect = usableCommitImpressionViewport(
        rect(0, 800, 0, 1_000),
        [{ edge: 'bottom', rect: rect(736, 64) }],
    );
    const target = environment.target(rect(620));
    client.observe('shifted', target, tokenFor('shifted'), TOKEN_EXPIRES_AT);
    environment.advance(600);
    environment.rects.set(target, rect(680));
    environment.emit('layoutchange');
    environment.advance(100);
    environment.rects.set(target, rect(620));
    environment.emit('layoutchange');
    environment.advance(999);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 0);

    environment.emit('layoutchange');
    environment.advance(SECOND);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 1);
    client.dispose();
});

void test('retries an immutable batch with its original server token after a newer token is served', async () => {
    const { environment, client } = setup();
    environment.outcomes.push(new Error('Network unavailable'), 'accepted');
    const first = environment.target();
    client.observe('first', first, tokenFor('first'), TOKEN_EXPIRES_AT);
    environment.advance(6 * SECOND);
    await settle();
    client.observe('first', first, 'newer-server-token', TOKEN_EXPIRES_AT);
    client.observe(
        'second',
        environment.target(),
        tokenFor('second'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(SECOND);
    await settle();
    assert.equal(environment.deliveries.length, 3);
    assert.strictEqual(
        environment.deliveries[0].batch,
        environment.deliveries[1].batch,
    );
    assert.equal(Object.isFrozen(environment.deliveries[0].batch), true);
    assert.equal(
        Object.isFrozen(environment.deliveries[0].batch.impressions),
        true,
    );
    assert.equal(
        Object.isFrozen(environment.deliveries[0].batch.impressions[0]),
        true,
    );
    assert.deepEqual(environment.deliveries[1].batch.impressions, [
        { commit_id: 'first', token: tokenFor('first') },
    ]);
    assert.deepEqual(commitIds(environment.deliveries[2].batch), ['second']);
    assert.notEqual(
        environment.deliveries[1].batch.batch_id,
        environment.deliveries[2].batch.batch_id,
    );
    client.dispose();
});

void test('bounds retries and discards expired retry batches after tab suspension', async () => {
    const { environment, client } = setup();
    environment.outcomes.push('retry', 'retry', 'retry', 'retry');
    client.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(6 * SECOND);
    await settle();
    for (const delay of [SECOND, 5 * SECOND, 30 * SECOND]) {
        environment.advance(delay);
        await settle();
    }
    assert.equal(environment.deliveries.length, 4);
    assert.equal(environment.timers.size, 0);
    client.dispose();

    const expired = setup();
    expired.environment.outcomes.push('retry');
    expired.client.observe(
        'commit',
        expired.environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    expired.environment.advance(6 * SECOND);
    await settle();
    expired.environment.time += 3 * 60 * SECOND;
    expired.environment.visible = false;
    expired.environment.emit('visibilitychange');
    assert.equal(expired.environment.deliveries.length, 1);
    assert.equal(expired.environment.timers.size, 0);
    expired.client.dispose();
});

void test('times out a hung request and drains later views using the same retry batch', async () => {
    const { environment, client } = setup();
    environment.pendingDelivery = new Promise(() => {});
    client.observe(
        'original',
        environment.target(),
        tokenFor('original'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(6 * SECOND);
    await settle();

    client.observe(
        'later',
        environment.target(),
        tokenFor('later'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(SECOND);
    environment.pendingDelivery = null;
    environment.advance(9 * SECOND);
    await settle();

    assert.equal(environment.deliveries[0].signal.aborted, true);
    environment.advance(SECOND);
    await settle();

    assert.equal(environment.deliveries.length, 3);
    assert.strictEqual(
        environment.deliveries[0].batch,
        environment.deliveries[1].batch,
    );
    assert.deepEqual(commitIds(environment.deliveries[2].batch), ['later']);
    assert.equal(environment.timers.size, 0);
    client.dispose();
});

void test('keeps queued events bounded and requests at most 50 IDs while a request is pending', async () => {
    const { environment, client } = setup();
    let resolveDelivery: (result: DeliveryResult) => void = () => {};
    environment.pendingDelivery = new Promise((resolve) => {
        resolveDelivery = resolve;
    });
    for (let index = 0; index < 620; index += 1) {
        client.observe(
            `commit-${index}`,
            environment.target(),
            tokenFor(`commit-${index}`),
            TOKEN_EXPIRES_AT,
        );
    }
    environment.advance(SECOND);
    assert.equal(environment.deliveries.length, 1);
    environment.pendingDelivery = null;
    resolveDelivery('accepted');
    await settle();
    assert.equal(environment.deliveries.length, 11);
    assert.ok(
        environment.deliveries.every(
            ({ batch }) => batch.impressions.length <= 50,
        ),
    );
    assert.equal(
        environment.deliveries.reduce(
            (sum, { batch }) => sum + batch.impressions.length,
            0,
        ),
        520,
    );
    client.dispose();
});

void test('page hide delivers newer pending views while the original request remains stalled', async () => {
    const { environment, client } = setup();
    let resolveDelivery: (result: DeliveryResult) => void = () => {};
    environment.pendingDelivery = new Promise((resolve) => {
        resolveDelivery = resolve;
    });
    client.observe(
        'original',
        environment.target(),
        tokenFor('original'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(6 * SECOND);
    assert.equal(environment.deliveries.length, 1);
    assert.equal(environment.deliveries[0].keepalive, true);

    for (let index = 0; index < 63; index += 1) {
        client.observe(
            `new-${index}`,
            environment.target(),
            tokenFor(`new-${index}`),
            TOKEN_EXPIRES_AT,
        );
    }
    environment.advance(SECOND);
    environment.visible = false;
    environment.emit('visibilitychange');
    assert.equal(environment.deliveries.length, 3);
    assert.deepEqual(commitIds(environment.deliveries[0].batch), ['original']);
    assert.equal(commitIds(environment.deliveries[1].batch).length, 50);
    assert.equal(commitIds(environment.deliveries[2].batch).length, 13);
    assert.deepEqual(environment.deliveries[1].batch.impressions[0], {
        commit_id: 'new-0',
        token: tokenFor('new-0'),
    });
    assert.deepEqual(environment.deliveries[2].batch.impressions[0], {
        commit_id: 'new-50',
        token: tokenFor('new-50'),
    });
    assert.equal(
        new Set(environment.deliveries.map(({ batch }) => batch.batch_id)).size,
        3,
    );
    assert.ok(
        environment.deliveries.every(
            ({ batch, keepalive }) =>
                keepalive &&
                Object.isFrozen(batch) &&
                Object.isFrozen(batch.impressions),
        ),
    );
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 3);

    environment.pendingDelivery = null;
    resolveDelivery('accepted');
    await settle();
    environment.advance(10 * SECOND);
    assert.equal(environment.deliveries.length, 3);
    client.dispose();
});

void test('page hide bounds total in-flight token bytes and retains overflow until requests settle', async () => {
    const { environment, client } = setup();
    const completions: (() => void)[] = [];
    let outstandingBytes = 0;
    let peakBytes = 0;
    environment.deliver = (batch, options) => {
        const bytes = new TextEncoder().encode(
            JSON.stringify(batch),
        ).byteLength;
        outstandingBytes += bytes;
        peakBytes = Math.max(peakBytes, outstandingBytes);
        assert.ok(outstandingBytes <= 60 * 1_024);
        environment.deliveries.push({ batch, ...options });
        return new Promise((resolve) => {
            completions.push(() => {
                outstandingBytes -= bytes;
                resolve('accepted');
            });
        });
    };

    for (let index = 0; index < 220; index += 1) {
        client.observe(
            `019a0000-0000-7000-8000-${String(index).padStart(12, '0')}`,
            environment.target(),
            'a'.repeat(484),
            TOKEN_EXPIRES_AT,
        );
    }
    environment.advance(SECOND);
    environment.visible = false;
    environment.emit('visibilitychange');
    assert.equal(environment.deliveries.length, 2);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 2);

    let finished = 0;
    while (completions.length > 0) {
        assert.ok(finished++ < 20);
        completions.shift()!();
        await settle();
    }
    const delivered = environment.deliveries.flatMap(({ batch }) =>
        commitIds(batch),
    );
    assert.equal(delivered.length, 220);
    assert.equal(new Set(delivered).size, 220);
    assert.ok(peakBytes > 30 * 1_024);
    assert.equal(outstandingBytes, 0);
    client.dispose();
});

void test('waiting for keepalive capacity never exhausts retries during repeated page hides', async () => {
    const { environment, client } = setup();
    const completions: ((result: DeliveryResult) => void)[] = [];
    environment.deliver = (batch, options) => {
        environment.deliveries.push({ batch, ...options });
        return new Promise((resolve) => completions.push(resolve));
    };
    const expose = (first: number) => {
        for (let index = first; index < first + 100; index += 1) {
            client.observe(
                `019a0000-0000-7000-8000-${String(index).padStart(12, '0')}`,
                environment.target(),
                'a'.repeat(484),
                TOKEN_EXPIRES_AT,
            );
        }
        environment.advance(SECOND);
    };
    expose(0);
    environment.visible = false;
    environment.emit('visibilitychange');
    completions[0]('accepted');
    await settle();

    environment.visible = true;
    environment.emit('visibilitychange');
    expose(100);
    completions[3]('accepted');
    await settle();

    environment.visible = false;
    environment.emit('visibilitychange');
    await settle();
    environment.emit('pagehide');
    await settle();
    environment.emit('pagehide');
    await settle();
    for (let index = 0; index < completions.length; index += 1) {
        assert.ok(index < 20);
        completions[index]('accepted');
        await settle();
    }
    const delivered = environment.deliveries.flatMap(({ batch }) =>
        commitIds(batch),
    );
    assert.equal(delivered.length, 200);
    assert.equal(new Set(delivered).size, 200);
    client.dispose();
});

void test('dispose cancels exposures, listeners, queued events, and in-flight requests for account changes', async () => {
    const { environment, client } = setup();
    client.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(SECOND);
    client.dispose();
    environment.advance(10 * SECOND);
    assert.equal(environment.deliveries.length, 0);
    assert.equal(environment.observed.size, 0);
    assert.equal(environment.timers.size, 0);
    assert.ok(
        [...environment.listeners.values()].every(
            (callbacks) => callbacks.size === 0,
        ),
    );

    const next = new CommitImpressionClient(environment);
    next.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(6 * SECOND);
    assert.equal(environment.deliveries.length, 1);
    next.dispose();
    assert.equal(environment.deliveries[0].signal.aborted, true);
    await settle();
    assert.equal(environment.timers.size, 0);
});

void test('unmount and immediate remount restart timing safely and stop listeners when idle', () => {
    const { environment, client } = setup();
    const target = environment.target();
    const unobserve = client.observe(
        'commit',
        target,
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    environment.advance(999);
    unobserve();
    const cleanup = client.observe(
        'commit',
        target,
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    unobserve();
    environment.advance(999);
    environment.emit('pagehide');
    assert.equal(environment.deliveries.length, 0);
    cleanup();
    assert.equal(environment.timers.size, 0);
    assert.ok(
        [...environment.listeners.values()].every(
            (callbacks) => callbacks.size === 0,
        ),
    );
    client.dispose();
});

void test('does not install browser machinery until an eligible target is observed', () => {
    const { environment, client } = setup();
    assert.equal(environment.observerCount, 0);
    assert.equal(environment.listeners.size, 0);
    assert.equal(environment.timers.size, 0);
    client.dispose();
    client.observe(
        'commit',
        environment.target(),
        tokenFor('commit'),
        TOKEN_EXPIRES_AT,
    );
    assert.equal(environment.observerCount, 0);
});
