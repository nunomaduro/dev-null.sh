/**
 * Whether an embedded player is playing, as far as the page can tell. The player lives on another origin, so only
 * providers that report it over `postMessage` can say; for the rest it stays unknown.
 */
export type Playback = 'playing' | 'paused' | 'unknown';

type PlayerApi = 'youtube' | 'vimeo';

function playerApi(src: string): PlayerApi | null {
    try {
        const { hostname } = new URL(src);

        if (/(^|\.)youtube(-nocookie)?\.com$/.test(hostname)) {
            return 'youtube';
        }

        if (hostname === 'player.vimeo.com') {
            return 'vimeo';
        }
    } catch {
        return null;
    }

    return null;
}

/**
 * YouTube only reports its state to pages that ask for its API, and only to the origin they name.
 */
export function playerApiSrc(src: string, origin: string): string {
    if (playerApi(src) !== 'youtube') {
        return src;
    }

    const url = new URL(src);

    url.searchParams.set('enablejsapi', '1');
    url.searchParams.set('origin', origin);

    return url.toString();
}

function parsed(data: unknown): Record<string, unknown> | null {
    if (typeof data === 'string') {
        try {
            const value: unknown = JSON.parse(data);

            return typeof value === 'object' && value !== null
                ? (value as Record<string, unknown>)
                : null;
        } catch {
            return null;
        }
    }

    return typeof data === 'object' && data !== null
        ? (data as Record<string, unknown>)
        : null;
}

/**
 * YouTube's states: -1 unstarted, 0 ended, 1 playing, 2 paused, 3 buffering, 5 cued. Buffering is still playing, as
 * far as the viewer is concerned.
 */
function youtubePlayback(state: unknown): Playback | null {
    if (state === 1 || state === 3) {
        return 'playing';
    }

    return [-1, 0, 2, 5].includes(state as number) ? 'paused' : null;
}

export function playbackFromMessage(
    src: string,
    data: unknown,
): Playback | null {
    const api = playerApi(src);
    const message = parsed(data);

    if (api === null || message === null) {
        return null;
    }

    if (api === 'youtube') {
        if (message.event === 'onStateChange') {
            return youtubePlayback(message.info);
        }

        const info = parsed(message.info);

        return message.event === 'infoDelivery' && info !== null
            ? youtubePlayback(info.playerState)
            : null;
    }

    if (message.event === 'play' || message.event === 'playing') {
        return 'playing';
    }

    return message.event === 'pause' || message.event === 'ended'
        ? 'paused'
        : null;
}

/**
 * The messages that ask a player to start reporting its state, sent again until it answers, since it may not be
 * listening yet when the frame first loads.
 */
export function subscriptionMessages(src: string): string[] {
    const api = playerApi(src);

    if (api === 'youtube') {
        return [
            JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }),
            JSON.stringify({
                event: 'command',
                func: 'addEventListener',
                args: ['onStateChange'],
                id: 1,
                channel: 'widget',
            }),
        ];
    }

    if (api === 'vimeo') {
        return ['play', 'pause', 'ended'].map((value) =>
            JSON.stringify({ method: 'addEventListener', value }),
        );
    }

    return [];
}

export function watchPlayback(
    frame: HTMLIFrameElement,
    src: string,
    onChange: (playback: Playback) => void,
): () => void {
    const messages = subscriptionMessages(src);

    if (messages.length === 0) {
        return () => undefined;
    }

    const targetOrigin = new URL(src).origin;
    let hasAnswered = false;
    let attempts = 0;

    const subscribe = () => {
        messages.forEach((message) =>
            frame.contentWindow?.postMessage(message, targetOrigin),
        );
    };

    const retry = window.setInterval(() => {
        if (hasAnswered || ++attempts > 40) {
            window.clearInterval(retry);

            return;
        }

        subscribe();
    }, 250);

    const onMessage = (event: MessageEvent) => {
        if (event.source !== frame.contentWindow) {
            return;
        }

        // A player's first word is usually that it is ready, which is when asking it to report is sure to be heard.
        if (!hasAnswered) {
            hasAnswered = true;
            subscribe();
        }

        const playback = playbackFromMessage(src, event.data);

        if (playback !== null) {
            onChange(playback);
        }
    };

    window.addEventListener('message', onMessage);
    frame.addEventListener('load', subscribe);

    return () => {
        window.clearInterval(retry);
        window.removeEventListener('message', onMessage);
        frame.removeEventListener('load', subscribe);
    };
}

export type FullscreenTarget = HTMLElement & {
    webkitRequestFullscreen?: () => void;
};

/**
 * Safari on iPad still only knows the prefixed API, and Safari on iPhone can't put a frame full screen at all.
 */
export function canGoFullscreen(
    page: Pick<Document, 'fullscreenEnabled'> & {
        webkitFullscreenEnabled?: boolean;
    } = document,
): boolean {
    return page.fullscreenEnabled || page.webkitFullscreenEnabled === true;
}

export function goFullscreen(element: FullscreenTarget): void {
    if (typeof element.requestFullscreen === 'function') {
        void element.requestFullscreen().catch(() => undefined);

        return;
    }

    element.webkitRequestFullscreen?.();
}
