import { lazy, Suspense, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { closeVideo, useDockedVideo } from '@/lib/video-dock';

const DockedPlayer = lazy(() => import('@/components/docked-player'));

/**
 * Holds the playing video's player for the whole layout, so it keeps playing from page to page. Each new video gets a
 * player of its own.
 */
export function VideoDock() {
    const video = useDockedVideo();

    // Pages outside the layout have nowhere to keep the player, so leaving for one ends the video.
    useEffect(() => closeVideo, []);

    if (video === null) {
        return null;
    }

    return createPortal(
        <Suspense fallback={null}>
            <DockedPlayer key={video.src} video={video} />
        </Suspense>,
        document.body,
    );
}
