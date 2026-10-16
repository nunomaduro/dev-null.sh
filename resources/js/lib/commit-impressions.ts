const VISIBILITY_DURATION = 1_000;
const REPEAT_WINDOW = 30 * 60 * 1_000;
const FLUSH_DELAY = 5_000;
const FLUSH_SIZE = 20;
const MAX_BATCH_SIZE = 50;
const MAX_PENDING = 500;
const MAX_KEEPALIVE_BYTES = 60 * 1_024;
const MAX_RETRY_AGE = 2 * 60 * 1_000;
const REQUEST_TIMEOUT = 10_000;
const RETRY_DELAYS = [1_000, 5_000, 30_000];

export interface ImpressionRect {
    top: number;
    right: number;
    bottom: number;
    left: number;
    width: number;
    height: number;
}

export interface CommitImpressionEvent {
    readonly commit_id: string;
    readonly token: string;
}

export interface CommitImpressionBatch {
    readonly batch_id: string;
    readonly impressions: readonly CommitImpressionEvent[];
}

type Timer = ReturnType<typeof setTimeout>;
type DeliveryResult = 'accepted' | 'retry' | 'discard';
type ImpressionEvent =
    | 'scroll'
    | 'resize'
    | 'layoutchange'
    | 'visibilitychange'
    | 'modalchange'
    | 'pagehide';

interface ImpressionObserver {
    observe(target: Element): void;
    unobserve(target: Element): void;
    disconnect(): void;
}

export interface ImpressionEnvironment {
    now(): number;
    setTimeout(callback: () => void, delay: number): Timer;
    clearTimeout(timer: Timer): void;
    isVisible(): boolean;
    viewport(): ImpressionRect;
    rect(target: Element): ImpressionRect;
    createObserver(onChange: (targets: Element[]) => void): ImpressionObserver;
    listen(event: ImpressionEvent, callback: () => void): () => void;
    uuid(): string;
    deliver(
        batch: CommitImpressionBatch,
        options: { keepalive: boolean; signal: AbortSignal },
    ): Promise<DeliveryResult>;
}

interface TrackedCommit {
    commitId: string;
    token: string;
    expiresAt: number;
    recorded: boolean;
    timer: Timer | null;
}

interface PendingBatch {
    payload: CommitImpressionBatch;
    createdAt: number;
    attempts: number;
}

export interface ImpressionOccluder {
    edge: 'top' | 'bottom';
    rect: ImpressionRect;
}

/** Subtract fixed navigation and headers only while pinned to a viewport edge. */
export function usableCommitImpressionViewport(
    viewport: ImpressionRect,
    occluders: readonly ImpressionOccluder[],
): ImpressionRect {
    let top = viewport.top;
    let bottom = viewport.bottom;
    for (const { edge, rect } of occluders) {
        if (
            rect.width <= 0 ||
            rect.height <= 0 ||
            rect.right <= viewport.left ||
            rect.left >= viewport.right ||
            rect.bottom <= viewport.top ||
            rect.top >= viewport.bottom
        ) {
            continue;
        }

        if (edge === 'top' && rect.top <= viewport.top) {
            top = Math.max(top, Math.min(rect.bottom, viewport.bottom));
        }
        if (edge === 'bottom' && rect.bottom >= viewport.bottom) {
            bottom = Math.min(bottom, Math.max(rect.top, viewport.top));
        }
    }

    return {
        ...viewport,
        top,
        bottom: Math.max(top, bottom),
        height: Math.max(0, bottom - top),
    };
}

/** Tall commits qualify against one viewport's worth of content. */
export function hasEnoughCommitVisibility(
    rect: ImpressionRect,
    viewport: ImpressionRect,
): boolean {
    const measurableArea =
        Math.min(rect.width, viewport.width) *
        Math.min(rect.height, viewport.height);
    const visibleWidth = Math.max(
        0,
        Math.min(rect.right, viewport.right) -
            Math.max(rect.left, viewport.left),
    );
    const visibleHeight = Math.max(
        0,
        Math.min(rect.bottom, viewport.bottom) -
            Math.max(rect.top, viewport.top),
    );

    return (
        measurableArea > 0 && visibleWidth * visibleHeight >= measurableArea / 2
    );
}

/** One instance belongs to one signed-in session and survives Inertia navigation. */
export class CommitImpressionClient {
    private readonly environment: ImpressionEnvironment;
    private readonly targets = new Map<Element, TrackedCommit>();
    private readonly pending = new Map<string, string>();
    private readonly lastQualified = new Map<
        string,
        { qualifiedAt: number; expiresAt: number }
    >();
    private inFlightBytes = 0;
    private readonly requests = new AbortController();
    private observer: ImpressionObserver | null = null;
    private removeListeners: (() => void)[] = [];
    private flushTimer: Timer | null = null;
    private geometryTimer: Timer | null = null;
    private retryTimer: Timer | null = null;
    private batch: PendingBatch | null = null;
    private sending = false;
    private disposed = false;

    constructor(environment: ImpressionEnvironment) {
        this.environment = environment;
    }

    observe(
        commitId: string,
        target: Element,
        token: string | null,
        expiresAt: number | null,
    ): () => void {
        if (
            this.disposed ||
            !token ||
            expiresAt === null ||
            !Number.isFinite(expiresAt) ||
            expiresAt * 1_000 <= this.environment.now()
        ) {
            return () => {};
        }

        this.start();
        const previous = this.targets.get(target);
        if (previous !== undefined) {
            this.cancelExposure(previous);
        }

        const tracked: TrackedCommit = {
            commitId,
            token,
            expiresAt: expiresAt * 1_000,
            recorded: false,
            timer: null,
        };
        this.targets.set(target, tracked);
        this.observer!.observe(target);
        this.updateVisibility(target, tracked);

        return () => {
            if (this.targets.get(target) !== tracked) {
                return;
            }
            this.cancelExposure(tracked);
            this.targets.delete(target);
            this.observer?.unobserve(target);
            this.stopWhenIdle();
        };
    }

    dispose(): void {
        this.disposed = true;
        this.requests.abort();
        for (const tracked of this.targets.values()) {
            this.cancelExposure(tracked);
        }
        this.targets.clear();
        this.pending.clear();
        this.lastQualified.clear();
        this.batch = null;
        this.clearTimer('flushTimer');
        this.clearTimer('geometryTimer');
        this.clearTimer('retryTimer');
        this.stop();
    }

    private start(): void {
        if (this.observer !== null) {
            return;
        }

        this.observer = this.environment.createObserver((targets) => {
            const viewport = this.environment.viewport();
            const pageVisible = this.environment.isVisible();
            for (const target of targets) {
                const tracked = this.targets.get(target);
                if (tracked !== undefined) {
                    this.updateVisibility(
                        target,
                        tracked,
                        viewport,
                        pageVisible,
                    );
                }
            }
        });
        const scheduleGeometry = () => {
            if (this.geometryTimer === null) {
                this.geometryTimer = this.environment.setTimeout(() => {
                    this.geometryTimer = null;
                    this.refreshVisibility();
                }, 0);
            }
        };
        this.removeListeners = [
            this.environment.listen('scroll', scheduleGeometry),
            this.environment.listen('resize', scheduleGeometry),
            this.environment.listen('layoutchange', scheduleGeometry),
            this.environment.listen('modalchange', () =>
                this.refreshVisibility(),
            ),
            this.environment.listen('visibilitychange', () => {
                this.refreshVisibility();
                if (!this.environment.isVisible()) {
                    this.flush(true);
                }
            }),
            this.environment.listen('pagehide', () => {
                for (const tracked of this.targets.values()) {
                    this.cancelExposure(tracked);
                }
                this.flush(true);
            }),
        ];
    }

    private stop(): void {
        this.observer?.disconnect();
        this.observer = null;
        this.removeListeners.forEach((remove) => remove());
        this.removeListeners = [];
        this.clearTimer('geometryTimer');
    }

    private stopWhenIdle(): void {
        if (
            this.targets.size === 0 &&
            this.pending.size === 0 &&
            this.batch === null
        ) {
            this.stop();
        }
    }

    private refreshVisibility(): void {
        const viewport = this.environment.viewport();
        const pageVisible = this.environment.isVisible();
        for (const [target, tracked] of this.targets) {
            this.updateVisibility(target, tracked, viewport, pageVisible);
        }
    }

    private updateVisibility(
        target: Element,
        tracked: TrackedCommit,
        viewport = this.environment.viewport(),
        pageVisible = this.environment.isVisible(),
    ): void {
        const visible =
            tracked.expiresAt > this.environment.now() &&
            pageVisible &&
            hasEnoughCommitVisibility(this.environment.rect(target), viewport);

        if (!visible) {
            this.cancelExposure(tracked);
            return;
        }

        if (tracked.recorded || tracked.timer !== null) {
            return;
        }

        tracked.timer = this.environment.setTimeout(() => {
            tracked.timer = null;
            if (
                this.targets.get(target) !== tracked ||
                tracked.expiresAt <= this.environment.now() ||
                !this.environment.isVisible() ||
                !hasEnoughCommitVisibility(
                    this.environment.rect(target),
                    this.environment.viewport(),
                )
            ) {
                this.cancelExposure(tracked);
                return;
            }

            tracked.recorded = true;
            this.qualify(tracked.commitId, tracked.token, tracked.expiresAt);
        }, VISIBILITY_DURATION);
    }

    private cancelExposure(tracked: TrackedCommit): void {
        if (tracked.timer !== null) {
            this.environment.clearTimeout(tracked.timer);
            tracked.timer = null;
        }
        tracked.recorded = false;
    }

    private qualify(commitId: string, token: string, expiresAt: number): void {
        const now = this.environment.now();
        const last = this.lastQualified.get(commitId);
        if (
            last !== undefined &&
            now - last.qualifiedAt < REPEAT_WINDOW &&
            now < last.expiresAt
        ) {
            return;
        }

        for (const [id, qualified] of this.lastQualified) {
            if (
                now - qualified.qualifiedAt >= REPEAT_WINDOW ||
                now >= qualified.expiresAt
            ) {
                this.lastQualified.delete(id);
            }
        }
        if (this.pending.size >= MAX_PENDING) {
            return;
        }

        this.lastQualified.set(commitId, { qualifiedAt: now, expiresAt });
        this.pending.set(commitId, token);
        if (this.pending.size >= FLUSH_SIZE) {
            this.flush();
        } else if (this.flushTimer === null) {
            this.flushTimer = this.environment.setTimeout(() => {
                this.flushTimer = null;
                this.flush();
            }, FLUSH_DELAY);
        }
    }

    private flush(keepalive = !this.environment.isVisible()): void {
        if (this.disposed) {
            return;
        }
        if (this.sending) {
            if (keepalive) {
                this.flushPendingOnHide();
            }
            return;
        }
        this.clearTimer('flushTimer');

        if (this.batch !== null) {
            if (this.environment.now() - this.batch.createdAt > MAX_RETRY_AGE) {
                this.clearTimer('retryTimer');
                this.batch = null;
            } else if (this.retryTimer !== null) {
                if (!keepalive) {
                    return;
                }
                this.clearTimer('retryTimer');
            }
        }

        if (this.batch === null && this.pending.size > 0) {
            const payload = this.takePending();
            if (payload === null) {
                return;
            }
            this.batch = {
                payload,
                createdAt: this.environment.now(),
                attempts: 0,
            };
        }

        const batch = this.batch;
        if (batch === null) {
            this.stopWhenIdle();
            return;
        }
        if (
            this.inFlightBytes + this.payloadBytes(batch.payload) >
            MAX_KEEPALIVE_BYTES
        ) {
            return;
        }
        this.sending = true;
        batch.attempts += 1;

        void this.deliverBatch(batch.payload).then((result) => {
            if (this.disposed) {
                return;
            }
            this.sending = false;
            const retryDelay = RETRY_DELAYS[batch.attempts - 1];
            if (
                result === 'retry' &&
                retryDelay !== undefined &&
                this.environment.now() + retryDelay - batch.createdAt <=
                    MAX_RETRY_AGE
            ) {
                this.retryTimer = this.environment.setTimeout(() => {
                    this.retryTimer = null;
                    this.flush();
                }, retryDelay);
                return;
            }

            this.batch = null;
            if (this.pending.size > 0) {
                // A completed request must not strand newer events.
                this.flush();
            } else {
                this.clearTimer('flushTimer');
                this.stopWhenIdle();
            }
        });

        if (keepalive) {
            this.flushPendingOnHide();
        }
    }

    private takePending(
        availableBytes = MAX_KEEPALIVE_BYTES,
    ): CommitImpressionBatch | null {
        const impressions = [...this.pending]
            .slice(0, MAX_BATCH_SIZE)
            .map(([commit_id, token]) => Object.freeze({ commit_id, token }));
        const payload = Object.freeze({
            batch_id: this.environment.uuid(),
            impressions: Object.freeze(impressions),
        });
        if (this.payloadBytes(payload) > availableBytes) {
            return null;
        }
        impressions.forEach(({ commit_id }) => this.pending.delete(commit_id));
        return payload;
    }

    private flushPendingOnHide(): void {
        this.clearTimer('flushTimer');
        while (this.pending.size > 0) {
            const payload = this.takePending(
                MAX_KEEPALIVE_BYTES - this.inFlightBytes,
            );
            if (payload === null) {
                return;
            }

            // A stalled ordinary request must not strand newer qualified views
            // during page exit. These bounded final batches are best effort.
            void this.deliverBatch(payload).then(() => {
                if (
                    !this.disposed &&
                    (this.batch !== null || this.pending.size > 0)
                ) {
                    this.flush(true);
                }
            });
        }
    }

    private payloadBytes(batch: CommitImpressionBatch): number {
        return new TextEncoder().encode(JSON.stringify(batch)).byteLength;
    }

    private deliverBatch(
        batch: CommitImpressionBatch,
    ): Promise<DeliveryResult> {
        const bytes = this.payloadBytes(batch);
        if (this.inFlightBytes + bytes > MAX_KEEPALIVE_BYTES) {
            return Promise.resolve('retry');
        }
        this.inFlightBytes += bytes;
        return new Promise((resolve) => {
            const controller = new AbortController();
            let settled = false;
            const finish = (result: DeliveryResult) => {
                if (settled) {
                    return;
                }
                settled = true;
                this.inFlightBytes -= bytes;
                this.environment.clearTimeout(timer);
                this.requests.signal.removeEventListener('abort', cancel);
                resolve(result);
            };
            const cancel = () => {
                controller.abort();
                finish('discard');
            };
            const timer = this.environment.setTimeout(() => {
                controller.abort();
                finish('retry');
            }, REQUEST_TIMEOUT);

            if (this.requests.signal.aborted) {
                cancel();
                return;
            }
            this.requests.signal.addEventListener('abort', cancel, {
                once: true,
            });
            try {
                void this.environment
                    .deliver(batch, {
                        keepalive: true,
                        signal: controller.signal,
                    })
                    .then(finish, () => finish('retry'));
            } catch {
                finish('retry');
            }
        });
    }

    private clearTimer(
        name: 'flushTimer' | 'geometryTimer' | 'retryTimer',
    ): void {
        const timer = this[name];
        if (timer !== null) {
            this.environment.clearTimeout(timer);
            this[name] = null;
        }
    }
}

function browserEnvironment(): ImpressionEnvironment {
    const hasBlockingDialog = () =>
        document.querySelector(
            '[data-slot="dialog-overlay"][data-state="open"], dialog[open], [role="dialog"][aria-modal="true"]',
        ) !== null;

    return {
        now: () => Date.now(),
        setTimeout: (callback, delay) => setTimeout(callback, delay),
        clearTimeout: (timer) => clearTimeout(timer),
        isVisible: () =>
            document.visibilityState === 'visible' && !hasBlockingDialog(),
        viewport: () => {
            const viewport = {
                top: 0,
                left: 0,
                bottom: window.innerHeight,
                right: window.innerWidth,
                width: window.innerWidth,
                height: window.innerHeight,
            };
            const occluders: ImpressionOccluder[] = [];
            for (const element of document.querySelectorAll<HTMLElement>(
                '[data-impression-occluder]',
            )) {
                const edge = element.dataset.impressionOccluder;
                if (edge !== 'top' && edge !== 'bottom') {
                    continue;
                }
                const style = window.getComputedStyle(element);
                if (style.visibility === 'hidden' || style.opacity === '0') {
                    continue;
                }
                occluders.push({ edge, rect: element.getBoundingClientRect() });
            }
            return usableCommitImpressionViewport(viewport, occluders);
        },
        rect: (target) => target.getBoundingClientRect(),
        createObserver: (onChange) =>
            new IntersectionObserver(
                (entries) => onChange(entries.map((entry) => entry.target)),
                { threshold: [0, 0.5, 1] },
            ),
        listen: (event, callback) => {
            if (event === 'layoutchange') {
                if (typeof ResizeObserver === 'undefined') {
                    return () => {};
                }
                const observer = new ResizeObserver(callback);
                observer.observe(document.body);
                return () => observer.disconnect();
            }
            if (event === 'modalchange') {
                let wasBlocked = hasBlockingDialog();
                const observer = new MutationObserver(() => {
                    const blocked = hasBlockingDialog();
                    if (blocked !== wasBlocked) {
                        wasBlocked = blocked;
                        callback();
                    }
                });
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: [
                        'data-state',
                        'open',
                        'aria-modal',
                        'role',
                    ],
                });
                return () => observer.disconnect();
            }
            const target = event === 'visibilitychange' ? document : window;
            const options = { passive: true, capture: event === 'scroll' };
            target.addEventListener(event, callback, options);
            return () => target.removeEventListener(event, callback, options);
        },
        uuid: () => {
            if (typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }

            // Local HTTP development origins may only expose getRandomValues.
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, (byte) =>
                byte.toString(16).padStart(2, '0'),
            ).join('');
            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        },
        deliver: async (batch, { keepalive, signal }) => {
            const csrfCookie = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
            const token = csrfCookie?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/commit-impressions', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive,
                signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token === undefined
                        ? {}
                        : { 'X-XSRF-TOKEN': decodeURIComponent(token) }),
                },
                body: JSON.stringify(batch),
            });
            await response.arrayBuffer();

            if (response.ok) {
                return 'accepted';
            }
            return response.status >= 500 ||
                [408, 425, 429].includes(response.status)
                ? 'retry'
                : 'discard';
        },
    };
}

let session: { viewerId: string; client: CommitImpressionClient } | null = null;

export function commitImpressionsFor(
    viewerId: string | null,
): CommitImpressionClient | null {
    if (session !== null && session.viewerId !== viewerId) {
        session.client.dispose();
        session = null;
    }

    if (
        viewerId === null ||
        typeof window === 'undefined' ||
        typeof IntersectionObserver === 'undefined'
    ) {
        return null;
    }

    session ??= {
        viewerId,
        client: new CommitImpressionClient(browserEnvironment()),
    };
    return session.client;
}

export function endCommitImpressionSession(viewerId: string | null): void {
    if (session?.viewerId === viewerId) {
        session.client.dispose();
        session = null;
    }
}
